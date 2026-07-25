<?php

namespace Tests\Feature;

use App\Models\TicketSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class OkohiSecurityTest extends TestCase
{
    use InteractsWithTenantTicketing, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTenantTicketingTablesExist();

        TicketSetting::query()->delete();
        TicketSetting::create([
            'company_name' => 'TEST TRANSPORT',
            'okohi_integration_key' => 'secret_key_12345',
        ]);
    }

    public function test_okohi_verify_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->getJson('/api/okohi/verify?ticket_id=TKT-12345')
            ->assertStatus(401)
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'Unauthorized');
    }

    public function test_okohi_verify_endpoint_accepts_valid_legacy_integration_key(): void
    {
        $this->withHeader('X-Okohi-Integration-Key', 'secret_key_12345')
            ->getJson('/api/okohi/verify?ticket_id=TKT-NONEXISTENT')
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'Ticket not found, cancelled or refunded');
    }

    public function test_okohi_verify_endpoint_accepts_valid_hmac_signature(): void
    {
        $secret = 'secret_key_12345';
        $signature = hash_hmac('sha256', '', $secret);

        $this->get('/api/okohi/verify?ticket_id=TKT-NONEXISTENT', [
            'X-Okohi-Signature' => $signature,
        ])
            ->assertOk()
            ->assertJsonPath('valid', false);
    }

    public function test_okohi_verify_endpoint_rejects_invalid_hmac_signature(): void
    {
        $this->withHeader('X-Okohi-Signature', 'invalid_signature_hash')
            ->getJson('/api/okohi/verify?ticket_id=TKT-NONEXISTENT')
            ->assertStatus(401);
    }

    public function test_okohi_verify_endpoint_rejects_expired_timestamp(): void
    {
        $secret = 'secret_key_12345';
        $signature = hash_hmac('sha256', '', $secret);
        $expiredTimestamp = time() - 600; // 10 minutes ago

        $this->withHeader('X-Okohi-Signature', $signature)
            ->withHeader('X-Okohi-Timestamp', (string) $expiredTimestamp)
            ->getJson('/api/okohi/verify?ticket_id=TKT-NONEXISTENT')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Request timestamp out of bounds');
    }

    public function test_okohi_claims_status_endpoint_requires_security_verification(): void
    {
        $this->getJson('/api/okohi/claims/test-claim-uuid/status')
            ->assertStatus(401);
    }
}
