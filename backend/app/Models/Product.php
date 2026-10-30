<?php

namespace App\Models;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\StockType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

// seller_profile_id, slug, status, moderation fields and is_featured are never mass assigned.
#[Fillable([
    'category_id', 'name', 'description', 'price_santim', 'discount_percent', 'discount_ends_at',
    'condition', 'stock_type', 'stock_quantity', 'production_days',
    'width_cm', 'depth_cm', 'height_cm', 'material', 'color',
    'area_id', 'offers_delivery', 'pickup_available', 'delivery_fee_santim', 'delivery_notes',
])]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Fields whose change sends a published listing back to moderation.
     */
    public const MODERATED_FIELDS = ['name', 'description', 'category_id', 'material', 'condition'];

    protected $attributes = [
        'status' => 'pending_review',
    ];

    protected function casts(): array
    {
        return [
            'price_santim' => 'integer',
            'discount_percent' => 'integer',
            'discount_ends_at' => 'datetime',
            'delivery_fee_santim' => 'integer',
            'stock_quantity' => 'integer',
            'cost_price_santim' => 'integer',
            'min_stock_level' => 'integer',
            'reorder_quantity' => 'integer',
            'production_days' => 'integer',
            'offers_delivery' => 'boolean',
            'pickup_available' => 'boolean',
            'is_featured' => 'boolean',
            'condition' => ProductCondition::class,
            'stock_type' => StockType::class,
            'status' => ProductStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** The supplier the store usually buys this from. Private to the store. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Options a customer can pick from, in the store's order. */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }

    /** The first photo that can be shown, i.e. that has its public copies (originals stay private). */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(['sort_order' => 'min', 'id' => 'min'], fn ($q) => $q->whereNotNull('large_path'));
    }

    /**
     * The listing's own delivery fee as it applies now. A fee above 0 counts only while admins let
     * stores set their own fees; free delivery (0) always counts. Null: the marketplace prices it.
     */
    public function ownDeliveryFeeSantim(bool $storesSetFee): ?int
    {
        $fee = $this->delivery_fee_santim;

        return $fee === 0 || ($fee !== null && $storesSetFee) ? $fee : null;
    }

    /** The store's sale percent while the sale runs, else null. */
    public function activeDiscountPercent(): ?int
    {
        $percent = (int) $this->discount_percent;

        return $percent > 0 && ($this->discount_ends_at === null || $this->discount_ends_at->isFuture()) ? $percent : null;
    }

    /** What a customer pays now for this listing, or for one of its options priced at $listPrice. */
    public function priceNowSantim(?int $listPrice = null): int
    {
        return self::discounted($listPrice ?? (int) $this->price_santim, $this->activeDiscountPercent());
    }

    /** A price less a percent, the amount off rounded half up to the santim. */
    public static function discounted(int $priceSantim, ?int $percent): int
    {
        return $percent ? $priceSantim - intdiv($priceSantim * $percent + 50, 100) : $priceSantim;
    }

    /**
     * SQL for what a listing costs now, the same sum as discounted(), for filtering and sorting by price.
     *
     * @return array{0: string, 1: array<int, mixed>} the expression and its bindings
     */
    public static function priceNowSql(): array
    {
        // Whole-number division: DIV on MySQL; SQLite divides integers that way already.
        $div = DB::connection()->getDriverName() === 'mysql' ? 'DIV' : '/';

        return ["(CASE WHEN products.discount_percent > 0 AND (products.discount_ends_at IS NULL OR products.discount_ends_at > ?)
            THEN products.price_santim - (products.price_santim * products.discount_percent + 50) {$div} 100 ELSE products.price_santim END)", [now()]];
    }

    /** Listings on sale right now. */
    public function scopeOnSale(Builder $query): void
    {
        $query->where('products.discount_percent', '>', 0)
            ->where(fn (Builder $q) => $q->whereNull('products.discount_ends_at')->orWhere('products.discount_ends_at', '>', now()));
    }

    public function isAvailable(): bool
    {
        return $this->stock_type === StockType::MadeToOrder || $this->stock_quantity > 0;
    }

    /**
     * Listings that guests may see: published, in an active category, from an approved, active seller.
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('products.status', ProductStatus::Published->value)
            ->whereHas('seller', fn (Builder $q) => $q->publiclyVisible())
            ->whereHas('category', fn (Builder $q) => $q->active());
    }

    /**
     * Case-insensitive keyword search over name, description, material and color.
     * LIKE wildcards in user input are escaped so "%" and "_" match literally.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $words = array_slice(preg_split('/\s+/u', mb_substr($term, 0, 100)) ?: [], 0, 6);

        foreach ($words as $word) {
            // "!" is the escape character because a backslash literal is not portable to MySQL.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($word));
            $like = '%'.$escaped.'%';
            $query->where(function (Builder $q) use ($like) {
                foreach (['name', 'description', 'material', 'color'] as $column) {
                    $q->orWhereRaw("LOWER(products.$column) LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }
    }
}
