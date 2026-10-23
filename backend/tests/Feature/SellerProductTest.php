<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SellerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerProductTest extends TestCase
{
    use RefreshDatabase;

    private SellerProfile $seller;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.distance.stores_set_fee' => true]);
        Storage::fake('public');
        Storage::fake('local');
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
        Sanctum::actingAs($this->seller->user);
    }

    public function test_approved_seller_creates_listing_with_photos_and_submits_for_review(): void
    {
        $response = $this->post('/api/seller/products', $this->productPayload([
            'price' => '38500.50',
            'delivery_fee' => '350',
            'action' => 'submit',
            'images' => [$this->photo('a.jpg'), $this->photo('b.png')],
        ]), ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.price', '38500.50')
            ->assertJsonPath('data.delivery.fee', '350.00')
            ->assertJsonCount(2, 'data.images');

        $product = Product::findOrFail($response->json('data.id'));
        $this->assertSame(3850050, $product->price_santim);
        $this->assertSame($this->seller->area_id, $product->area_id);
        foreach ($product->images as $image) {
            // The original keeps camera data, so it stays private; only the copies are public.
            Storage::disk('local')->assertExists($image->path);
            Storage::disk('public')->assertMissing($image->path);
            Storage::disk('public')->assertExists($image->copies());
            $this->assertStringStartsWith("products/{$product->id}/", $image->path);
            $this->assertStringNotContainsString('a.jpg', $image->path);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'subject_id' => $product->id]);

        // Still hidden from the public until an admin approves it.
        $this->getJson("/api/products/{$product->slug}")->assertNotFound();
    }

    public function test_seller_cannot_set_protected_fields(): void
    {
        $other = $this->approvedSeller();

        $id = $this->postJson('/api/seller/products', $this->productPayload([
            'status' => 'published',
            'seller_profile_id' => $other->id,
            'is_featured' => true,
            'slug' => 'hijack',
        ]))->assertCreated()->json('data.id');

        $product = Product::findOrFail($id);
        $this->assertSame('draft', $product->status->value);
        $this->assertSame($this->seller->id, $product->seller_profile_id);
        $this->assertFalse($product->is_featured);
        $this->assertNotSame('hijack', $product->slug);
    }

    public function test_submit_requires_a_photo(): void
    {
        $this->postJson('/api/seller/products', $this->productPayload(['action' => 'submit']))
            ->assertUnprocessable()->assertJsonValidationErrors('images');

        $id = $this->postJson('/api/seller/products', $this->productPayload())->assertCreated()->json('data.id');
        $this->postJson("/api/seller/products/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors('images');

        $this->post("/api/seller/products/{$id}/images", ['images' => [$this->photo()]], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/seller/products/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->postJson("/api/seller/products/{$id}/submit")->assertUnprocessable();
    }

    public function test_pending_seller_can_draft_but_not_submit(): void
    {
        $pending = $this->pendingSeller();
        Sanctum::actingAs($pending->user);

        $this->postJson('/api/seller/products', $this->productPayload())->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->post('/api/seller/products', $this->productPayload(['action' => 'submit', 'images' => [$this->photo()]]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    public function test_suspended_seller_cannot_write(): void
    {
        $id = $this->postJson('/api/seller/products', $this->productPayload())->json('data.id');
        $this->seller->forceFill(['status' => 'suspended'])->save();
        // Each real request loads the user fresh; mirror that instead of reusing the cached relation.
        Sanctum::actingAs($this->seller->user->fresh());

        $this->postJson('/api/seller/products', $this->productPayload())->assertForbidden();
        $this->putJson("/api/seller/products/{$id}", ['name' => 'New name'])->assertForbidden();
        // Reading their own listings is still allowed.
        $this->getJson('/api/seller/products')->assertOk();
    }

    public function test_validation_rules(): void
    {
        $cases = [
            ['price' => '12,500'],
            ['price' => '-100'],
            ['price' => '0.50'],
            ['price' => '100.999'],
            ['delivery_fee' => 'free'],
            ['category_id' => 99999],
            ['condition' => 'broken'],
            ['stock_type' => 'in_stock', 'stock_quantity' => null],
            ['stock_type' => 'made_to_order', 'production_days' => 21],
            ['offers_delivery' => false, 'pickup_available' => false],
            ['width_cm' => 5000],
            ['name' => 'ab'],
        ];

        foreach ($cases as $override) {
            $this->postJson('/api/seller/products', $this->productPayload($override))
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_inactive_category_is_rejected(): void
    {
        $this->category('dining')->update(['is_active' => false]);

        $this->postJson('/api/seller/products', $this->productPayload(['category_id' => $this->category('dining')->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_listings_are_finished_furniture_in_stock(): void
    {
        $this->postJson('/api/seller/products', $this->productPayload(['stock_type' => 'made_to_order', 'production_days' => 21]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_type');

        // Without a stock type the listing is in stock; leftover custom and production fields are ignored.
        $payload = $this->productPayload(['stock_quantity' => 4, 'production_days' => 21, 'custom_order_available' => true]);
        unset($payload['stock_type']);
        $id = $this->postJson('/api/seller/products', $payload)->assertCreated()
            ->assertJsonPath('data.stock_type', 'in_stock')
            ->assertJsonPath('data.stock_quantity', 4)
            ->assertJsonPath('data.production_days', null)
            ->assertJsonMissingPath('data.custom_order_available')
            ->json('data.id');

        $this->postJson('/api/seller/products', array_merge($payload, ['stock_quantity' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');
        $this->putJson("/api/seller/products/{$id}", ['stock_type' => 'made_to_order', 'production_days' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_type');
        $this->assertSame('in_stock', Product::find($id)->stock_type->value);
    }

    public function test_uploads_reject_non_images_oversized_and_too_many(): void
    {
        $id = $this->postJson('/api/seller/products', $this->productPayload())->json('data.id');
        $url = "/api/seller/products/{$id}/images";
        $json = ['Accept' => 'application/json'];

        $this->post($url, ['images' => [UploadedFile::fake()->create('evil.php', 10, 'application/x-php')]], $json)->assertUnprocessable();
        $this->post($url, ['images' => [UploadedFile::fake()->create('fake.jpg', 10, 'text/plain')]], $json)->assertUnprocessable();
        $this->post($url, ['images' => [UploadedFile::fake()->image('vector.svg')]], $json)->assertUnprocessable();
        $this->post($url, ['images' => [$this->photo('big.jpg', 6000)]], $json)->assertUnprocessable();
        $this->post($url, ['images' => [UploadedFile::fake()->image('tiny.jpg', 50, 50)]], $json)->assertUnprocessable();

        $max = config('marketplace.products.max_images');
        $this->post($url, ['images' => array_map(fn ($i) => $this->photo("p{$i}.jpg"), range(1, $max))], $json)->assertOk();
        $this->post($url, ['images' => [$this->photo('one-more.jpg')]], $json)->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->assertSame($max, Product::find($id)->images()->count());
    }

    public function test_sellers_cannot_touch_each_others_listings(): void
    {
        $theirs = Product::factory()->published()->create(['seller_profile_id' => $this->approvedSeller()->id, 'category_id' => $this->category()->id]);
        $imageId = $theirs->images()->first()->id;

        $this->getJson("/api/seller/products/{$theirs->id}")->assertNotFound();
        $this->putJson("/api/seller/products/{$theirs->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/seller/products/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/seller/products/{$theirs->id}/archive")->assertNotFound();
        $this->postJson("/api/seller/products/{$theirs->id}/submit")->assertNotFound();
        $this->post("/api/seller/products/{$theirs->id}/images", ['images' => [$this->photo()]], ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson("/api/seller/products/{$theirs->id}/images/{$imageId}")->assertNotFound();

        $this->assertSame([], collect($this->getJson('/api/seller/products')->json('data'))->pluck('id')->all());
        $theirs->refresh();
        $this->assertNotSame('Hijacked', $theirs->name);
        $this->assertSame('published', $theirs->status->value);
        $this->assertNull($theirs->deleted_at);
    }

    public function test_cannot_delete_image_through_own_product_id_with_foreign_image_id(): void
    {
        $mine = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);
        $mine->images()->create(['path' => 'products/x/second.jpg', 'sort_order' => 1]);
        $theirs = Product::factory()->published()->create(['seller_profile_id' => $this->approvedSeller()->id, 'category_id' => $this->category()->id]);

        $this->deleteJson("/api/seller/products/{$mine->id}/images/{$theirs->images()->first()->id}")->assertNotFound();
        $this->assertSame(1, $theirs->images()->count());
    }

    public function test_customers_and_admins_cannot_use_seller_area(): void
    {
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/seller/products')->assertForbidden();
        $this->postJson('/api/seller/products', $this->productPayload())->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/seller/products')->assertForbidden();
    }

    public function test_editing_published_listing_content_sends_it_back_to_review_but_price_and_stock_do_not(): void
    {
        $product = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);
        $slug = $product->slug;

        $this->putJson("/api/seller/products/{$product->id}", ['price' => '9999', 'stock_quantity' => 2])
            ->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.price_santim', 999900);

        $this->putJson("/api/seller/products/{$product->id}", ['name' => 'Renamed Sofa'])
            ->assertOk()->assertJsonPath('data.status', 'pending_review');

        // The public URL of a once-published listing does not change on rename.
        $this->assertSame($slug, $product->fresh()->slug);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.updated', 'subject_id' => $product->id]);
    }

    public function test_update_cannot_leave_listing_without_delivery_or_pickup(): void
    {
        $id = $this->postJson('/api/seller/products', $this->productPayload(['pickup_available' => false]))->json('data.id');

        $this->putJson("/api/seller/products/{$id}", ['offers_delivery' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('offers_delivery');
        $this->putJson("/api/seller/products/{$id}", ['offers_delivery' => false, 'pickup_available' => true])->assertOk();
        $this->putJson("/api/seller/products/{$id}", ['stock_type' => 'in_stock', 'stock_quantity' => 3])
            ->assertOk()->assertJsonPath('data.production_days', null)->assertJsonPath('data.stock_quantity', 3);
    }

    public function test_draft_rename_regenerates_slug(): void
    {
        $id = $this->postJson('/api/seller/products', $this->productPayload(['name' => 'Old Name']))->json('data.id');

        $this->putJson("/api/seller/products/{$id}", ['name' => 'Brand New Name'])
            ->assertOk()->assertJsonPath('data.slug', 'brand-new-name');
    }

    public function test_archived_listing_must_be_resubmitted_before_editing(): void
    {
        $product = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);

        $this->postJson("/api/seller/products/{$product->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson("/api/products/{$product->slug}")->assertNotFound();
        $this->putJson("/api/seller/products/{$product->id}", ['name' => 'Edit'])->assertUnprocessable();
        $this->postJson("/api/seller/products/{$product->id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
    }

    public function test_cannot_remove_last_photo_of_published_listing(): void
    {
        $product = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);

        $this->deleteJson("/api/seller/products/{$product->id}/images/{$product->images()->first()->id}")
            ->assertUnprocessable();

        $this->post("/api/seller/products/{$product->id}/images", ['images' => [$this->photo()]], ['Accept' => 'application/json'])->assertOk();
        $first = $product->images()->first();
        $this->deleteJson("/api/seller/products/{$product->id}/images/{$first->id}")->assertOk()->assertJsonCount(1, 'data.images');
    }

    public function test_delete_is_soft_and_hides_listing(): void
    {
        $product = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);

        $this->deleteJson("/api/seller/products/{$product->id}")->assertOk();
        $this->assertSoftDeleted($product);
        $this->getJson("/api/seller/products/{$product->id}")->assertNotFound();
        $this->getJson("/api/products/{$product->slug}")->assertNotFound();
    }

    public function test_seller_index_filters_by_status(): void
    {
        Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'status' => 'draft']);
        Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);

        $this->getJson('/api/seller/products')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/seller/products?status=draft')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'draft');
        $this->getJson('/api/seller/products?status=nope')->assertUnprocessable();
    }

    public function test_seller_profile_update_keeps_slug_and_cannot_change_status(): void
    {
        $slug = $this->seller->slug;

        // An approved store's name is changed by admins (SellerScopeTest); the status is never the store's to set.
        $this->putJson('/api/seller/profile', ['status' => 'suspended', 'area_id' => $this->area('arada')->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.slug', $slug)
            ->assertJsonPath('data.area', 'Arada');

        // While applying, the store can still fix its name, and the slug stays the same.
        $applicant = $this->pendingSeller();
        Sanctum::actingAs($applicant->user);
        $this->putJson('/api/seller/profile', ['store_name' => 'Renamed Store', 'status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.store_name', 'Renamed Store')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.slug', $applicant->slug);
    }
}
