<?php

namespace Tests\Feature;

use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function application(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tigist Alemu',
            'email' => 'tigist@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'store_name' => 'Tigist Woodworks',
            'business_type' => 'workshop',
            'store_phone' => '+251911223344',
            'area_id' => $this->area('yeka')->id,
        ], $overrides);
    }

    public function test_seller_application_creates_pending_store(): void
    {
        $this->postJson('/api/auth/register-seller', $this->application())
            ->assertCreated()
            ->assertJsonPath('user.role', 'seller')
            ->assertJsonPath('user.seller_profile.status', 'pending')
            ->assertJsonPath('user.seller_profile.slug', 'tigist-woodworks');

        $profile = SellerProfile::firstOrFail();
        $this->assertSame($this->area('yeka')->city_id, $profile->city_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.applied', 'subject_id' => $profile->id]);
    }

    public function test_application_cannot_self_approve(): void
    {
        $this->postJson('/api/auth/register-seller', $this->application(['status' => 'approved']))->assertCreated();

        $this->assertSame('pending', SellerProfile::firstOrFail()->status->value);
    }

    public function test_duplicate_store_names_get_unique_slugs(): void
    {
        $this->postJson('/api/auth/register-seller', $this->application())->assertCreated();
        $this->postJson('/api/auth/register-seller', $this->application(['email' => 'other@example.com']))
            ->assertCreated()
            ->assertJsonPath('user.seller_profile.slug', 'tigist-woodworks-2');
    }

    public function test_non_latin_store_name_still_gets_a_slug(): void
    {
        $this->postJson('/api/auth/register-seller', $this->application(['store_name' => 'የቤት እቃዎች']))
            ->assertCreated();

        $this->assertNotEmpty(SellerProfile::firstOrFail()->slug);
    }

    public function test_application_validation(): void
    {
        $this->postJson('/api/auth/register-seller', $this->application([
            'store_name' => '',
            'business_type' => 'bank',
            'store_phone' => 'call me',
            'area_id' => 99999,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['store_name', 'business_type', 'store_phone', 'area_id']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_failed_profile_creation_does_not_leave_orphan_user(): void
    {
        // An inactive area passes no validation, so nothing is written at all.
        $area = $this->area('lideta');
        $area->update(['is_active' => false]);

        $this->postJson('/api/auth/register-seller', $this->application(['area_id' => $area->id]))
            ->assertUnprocessable();

        $this->assertSame(0, User::count());
    }
}
