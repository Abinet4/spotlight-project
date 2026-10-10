<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->adminUser = $this->admin();
        Sanctum::actingAs($this->adminUser);
    }

    public function test_non_admins_are_forbidden(): void
    {
        $seller = $this->approvedSeller();

        foreach ([$this->customer(), $seller->user] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/admin/summary')->assertForbidden();
            $this->getJson('/api/admin/sellers')->assertForbidden();
            $this->postJson("/api/admin/sellers/{$seller->id}/approve")->assertForbidden();
            $this->postJson('/api/admin/categories', ['name' => 'Hack'])->assertForbidden();
        }
    }

    public function test_suspended_admin_is_forbidden(): void
    {
        $this->adminUser->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/api/admin/summary')->assertForbidden();
    }

    public function test_approve_pending_seller(): void
    {
        $seller = $this->pendingSeller();

        $this->getJson('/api/admin/sellers?status=pending')->assertOk()->assertJsonPath('data.0.id', $seller->id);

        $this->postJson("/api/admin/sellers/{$seller->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $seller->refresh();
        $this->assertSame($this->adminUser->id, $seller->reviewed_by);
        $this->assertNotNull($seller->reviewed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.approve', 'subject_id' => $seller->id, 'user_id' => $this->adminUser->id]);
    }

    public function test_reject_and_suspend_require_a_reason(): void
    {
        $pending = $this->pendingSeller();
        $this->postJson("/api/admin/sellers/{$pending->id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/sellers/{$pending->id}/reject", ['reason' => 'Incomplete documents'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.status_reason', 'Incomplete documents');

        $approved = $this->approvedSeller();
        $this->postJson("/api/admin/sellers/{$approved->id}/suspend")->assertUnprocessable();
        $this->postJson("/api/admin/sellers/{$approved->id}/suspend", ['reason' => 'Complaints'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/admin/sellers/{$approved->id}/reinstate")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_invalid_seller_transitions_are_rejected(): void
    {
        $pending = $this->pendingSeller();
        $approved = $this->approvedSeller();

        $this->postJson("/api/admin/sellers/{$pending->id}/suspend", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/admin/sellers/{$pending->id}/reinstate")->assertUnprocessable();
        $this->postJson("/api/admin/sellers/{$approved->id}/approve")->assertUnprocessable();
        $this->postJson("/api/admin/sellers/{$approved->id}/reject", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/admin/sellers/{$approved->id}/delete")->assertNotFound();
        $this->postJson('/api/admin/sellers/999999/approve')->assertNotFound();
    }

    public function test_suspending_seller_hides_their_listings_and_reinstating_restores_them(): void
    {
        $seller = $this->approvedSeller();
        $product = Product::factory()->published()->create(['seller_profile_id' => $seller->id, 'category_id' => $this->category()->id]);

        $this->postJson("/api/admin/sellers/{$seller->id}/suspend", ['reason' => 'Investigation'])->assertOk();
        $this->getJson("/api/products/{$product->slug}")->assertNotFound();

        $this->postJson("/api/admin/sellers/{$seller->id}/reinstate")->assertOk();
        $this->getJson("/api/products/{$product->slug}")->assertOk();
    }

    public function test_moderation_queue_approve_and_reject(): void
    {
        $seller = $this->approvedSeller();
        $product = Product::factory()->pendingReview()->create(['seller_profile_id' => $seller->id, 'category_id' => $this->category()->id]);

        $this->getJson('/api/admin/products')->assertOk()->assertJsonPath('data.0.id', $product->id)->assertJsonPath('data.0.status', 'pending_review');

        $this->postJson("/api/admin/products/{$product->id}/approve")->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertNotNull($product->fresh()->published_at);
        $this->getJson("/api/products/{$product->slug}")->assertOk();

        // Approving twice is refused; a published listing can be taken down with a note.
        $this->postJson("/api/admin/products/{$product->id}/approve")->assertUnprocessable();
        $this->postJson("/api/admin/products/{$product->id}/reject")->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/admin/products/{$product->id}/reject", ['note' => 'Photos show a different item'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.moderation_note', 'Photos show a different item');
        $this->getJson("/api/products/{$product->slug}")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.reject', 'subject_id' => $product->id]);
    }

    public function test_cannot_publish_listing_of_unapproved_seller_or_without_photos(): void
    {
        $pendingSellerProduct = Product::factory()->pendingReview()->create(['seller_profile_id' => $this->pendingSeller()->id, 'category_id' => $this->category()->id]);
        $this->postJson("/api/admin/products/{$pendingSellerProduct->id}/approve")->assertUnprocessable();

        $noPhotos = Product::factory()->create(['seller_profile_id' => $this->approvedSeller()->id, 'category_id' => $this->category()->id, 'status' => 'pending_review']);
        $this->postJson("/api/admin/products/{$noPhotos->id}/approve")->assertUnprocessable();

        $draft = Product::factory()->create(['seller_profile_id' => $this->approvedSeller()->id, 'category_id' => $this->category()->id, 'status' => 'draft']);
        $this->postJson("/api/admin/products/{$draft->id}/approve")->assertUnprocessable();
    }

    public function test_category_management(): void
    {
        $id = $this->postJson('/api/admin/categories', ['name' => 'Outdoor Furniture', 'sort_order' => 5])
            ->assertCreated()->assertJsonPath('data.slug', 'outdoor-furniture')->json('data.id');

        // Duplicate name gets a distinct slug.
        $this->postJson('/api/admin/categories', ['name' => 'Outdoor Furniture'])->assertCreated()->assertJsonPath('data.slug', 'outdoor-furniture-2');

        $this->postJson('/api/admin/categories', ['name' => 'Garden Chairs', 'parent_id' => $id])->assertCreated();
        $child = Category::where('slug', 'garden-chairs')->first();

        // Max two levels.
        $this->postJson('/api/admin/categories', ['name' => 'Too Deep', 'parent_id' => $child->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->putJson("/api/admin/categories/{$id}", ['parent_id' => $id])->assertUnprocessable();
        $this->putJson("/api/admin/categories/{$id}", ['parent_id' => $this->category()->id])->assertUnprocessable();
        $this->postJson('/api/admin/categories', ['name' => 'Bad', 'slug' => 'Bad Slug!'])->assertUnprocessable();

        $this->putJson("/api/admin/categories/{$id}", ['is_active' => false, 'name' => 'Outdoor'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.name', 'Outdoor');
        $this->getJson('/api/categories')->assertJsonMissing(['slug' => 'outdoor-furniture']);

        // In-use categories cannot be deleted, unused ones can.
        $this->deleteJson("/api/admin/categories/{$id}")->assertStatus(409);
        $used = $this->category();
        Product::factory()->create(['seller_profile_id' => $this->approvedSeller()->id, 'category_id' => $used->id]);
        $this->deleteJson("/api/admin/categories/{$used->id}")->assertStatus(409);
        $this->deleteJson("/api/admin/categories/{$child->id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $child->id]);
    }

    public function test_summary_and_audit_log(): void
    {
        $this->pendingSeller();
        $this->approvedSeller();
        $this->customer();

        $this->getJson('/api/admin/summary')
            ->assertOk()
            ->assertJsonPath('data.sellers.pending', 1)
            ->assertJsonPath('data.sellers.approved', 1)
            ->assertJsonPath('data.customers', 1);

        $seller = SellerProfile::where('status', 'pending')->first();
        $this->postJson("/api/admin/sellers/{$seller->id}/approve")->assertOk();
        $this->getJson('/api/admin/audit-logs?action=seller.')->assertOk()->assertJsonPath('data.0.action', 'seller.approve');
    }
}
