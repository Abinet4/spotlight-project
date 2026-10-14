<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerPhoneVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_phone_follows_the_config_flag(): void
    {
        $this->seedReferenceData();
        $seller = $this->approvedSeller();

        config(['marketplace.contact.show_seller_phone' => true]);
        $this->getJson("/api/sellers/{$seller->slug}")->assertOk()->assertJsonPath('data.phone', $seller->phone);
        $this->getJson('/api/config')->assertJsonPath('show_seller_phone', true);

        config(['marketplace.contact.show_seller_phone' => false]);
        $this->getJson("/api/sellers/{$seller->slug}")->assertOk()->assertJsonPath('data.phone', null);
        $this->getJson('/api/config')->assertJsonPath('show_seller_phone', false);
    }
}
