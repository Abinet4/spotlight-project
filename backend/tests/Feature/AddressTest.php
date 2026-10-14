<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AddressController;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->buyer = $this->customer();
        Sanctum::actingAs($this->buyer);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Home',
            'recipient_name' => 'Abebe Kebede',
            'phone' => '+251 911 234 567',
            'area_id' => $this->area('bole')->id,
            'address_line' => 'Near Edna Mall, house 12',
            'landmark' => 'Blue gate',
        ], $overrides);
    }

    public function test_first_address_becomes_default_and_default_moves(): void
    {
        $first = $this->postJson('/api/addresses', $this->payload())->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.area', 'Bole')
            ->json('data.id');
        $second = $this->postJson('/api/addresses', $this->payload(['label' => 'Office']))->assertCreated()
            ->assertJsonPath('data.is_default', false)->json('data.id');

        $this->postJson("/api/addresses/$second/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse(Address::find($first)->is_default);
        $this->getJson('/api/addresses')->assertJsonPath('data.0.id', $second)->assertJsonCount(2, 'data');

        $third = $this->postJson('/api/addresses', $this->payload(['is_default' => true]))->json('data.id');
        $this->assertSame([$third], Address::where('is_default', true)->pluck('id')->all());
    }

    public function test_deleting_default_promotes_another(): void
    {
        $a = $this->postJson('/api/addresses', $this->payload())->json('data.id');
        $b = $this->postJson('/api/addresses', $this->payload())->json('data.id');

        $this->deleteJson("/api/addresses/$a")->assertOk();
        $this->assertTrue(Address::find($b)->is_default);
        $this->deleteJson("/api/addresses/$b")->assertOk();
        $this->assertSame(0, Address::count());
        $this->deleteJson("/api/addresses/$b")->assertNotFound();
    }

    public function test_update_keeps_other_fields_and_validates(): void
    {
        $id = $this->postJson('/api/addresses', $this->payload())->json('data.id');
        $this->putJson("/api/addresses/$id", ['landmark' => 'Red gate'])->assertOk()
            ->assertJsonPath('data.landmark', 'Red gate')
            ->assertJsonPath('data.recipient_name', 'Abebe Kebede')
            ->assertJsonPath('data.is_default', true);

        $this->putJson("/api/addresses/$id", ['phone' => 'call me'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->putJson("/api/addresses/$id", ['area_id' => 99999])->assertUnprocessable()->assertJsonValidationErrors('area_id');
    }

    public function test_validation_and_inactive_area(): void
    {
        $this->postJson('/api/addresses', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_name', 'phone', 'area_id', 'address_line']);
        $this->postJson('/api/addresses', $this->payload(['phone' => '12345']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/addresses', $this->payload(['label' => str_repeat('x', 41)]))->assertUnprocessable()->assertJsonValidationErrors('label');

        $area = $this->area('lemi-kura');
        $area->update(['is_active' => false]);
        $this->postJson('/api/addresses', $this->payload(['area_id' => $area->id]))->assertUnprocessable()->assertJsonValidationErrors('area_id');
    }

    public function test_address_limit(): void
    {
        for ($i = 0; $i < AddressController::MAX_ADDRESSES; $i++) {
            $this->postJson('/api/addresses', $this->payload())->assertCreated();
        }
        $this->postJson('/api/addresses', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('address_line');
        $this->assertSame(AddressController::MAX_ADDRESSES, Address::count());
    }

    public function test_addresses_are_private_to_their_owner(): void
    {
        $id = $this->postJson('/api/addresses', $this->payload())->json('data.id');

        Sanctum::actingAs($this->customer());
        $this->getJson('/api/addresses')->assertJsonCount(0, 'data');
        $this->putJson("/api/addresses/$id", ['landmark' => 'hacked'])->assertNotFound();
        $this->postJson("/api/addresses/$id/default")->assertNotFound();
        $this->deleteJson("/api/addresses/$id")->assertNotFound();
        $this->assertSame('Blue gate', Address::find($id)->landmark);

        // Mass assignment cannot move an address to another user.
        $other = $this->postJson('/api/addresses', $this->payload(['user_id' => $this->buyer->id]))->json('data.id');
        $this->assertNotSame($this->buyer->id, Address::find($other)->user_id);

        Sanctum::actingAs($this->approvedSeller()->user);
        $this->getJson('/api/addresses')->assertForbidden();
    }
}
