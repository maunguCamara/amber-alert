<?php

namespace Tests\Feature\Webhooks;

use App\Services\AfricasTalkingService;
use App\Services\AmberApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ProcessInboundTip;
use Tests\TestCase;
use Mockery;

class WebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'amber.at_api_key'    => 'test-key',
            'amber.at_username'   => 'sandbox',
            'amber.at_short_code' => '22384',
        ]);
        Queue::fake();
    }

    // ── USSD endpoint ─────────────────────────────────────────────────────────

    /** @test */
    public function ussd_endpoint_returns_plain_text(): void
    {
        $response = $this->post('/webhooks/at/ussd', [
            'sessionId'   => 'ATUid_12345',
            'serviceCode' => '*384#',
            'phoneNumber' => '+254711111111',
            'text'        => '',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    /** @test */
    public function ussd_main_menu_contains_continue_prefix(): void
    {
        $response = $this->post('/webhooks/at/ussd', [
            'sessionId'   => 'ATUid_00001',
            'serviceCode' => '*384#',
            'phoneNumber' => '+254700000001',
            'text'        => '',
        ]);

        $this->assertStringStartsWith('CON', $response->getContent());
    }

    /** @test */
    public function ussd_police_option_returns_end_session(): void
    {
        $response = $this->post('/webhooks/at/ussd', [
            'sessionId'   => 'ATUid_00002',
            'serviceCode' => '*384#',
            'phoneNumber' => '+254700000002',
            'text'        => '3',
        ]);

        $this->assertStringStartsWith('END', $response->getContent());
    }

    /** @test */
    public function ussd_missing_session_id_still_processes(): void
    {
        // Africa's Talking always sends sessionId, but we should be defensive
        $response = $this->post('/webhooks/at/ussd', [
            'serviceCode' => '*384#',
            'phoneNumber' => '+254700000003',
            'text'        => '',
        ]);

        $response->assertStatus(200);
    }

    // ── AT delivery receipt ───────────────────────────────────────────────────

    /** @test */
    public function delivery_receipt_returns_no_content(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $response = $this->post('/webhooks/at/delivery', [
            'id'     => 'ATMid_abc123',
            'status' => 'Success',
        ]);

        $response->assertNoContent();
    }

    /** @test */
    public function delivery_receipt_with_empty_id_still_returns_204(): void
    {
        $response = $this->post('/webhooks/at/delivery', ['status' => 'Failed']);
        $response->assertNoContent();
    }

    // ── Inbound SMS ───────────────────────────────────────────────────────────

    /** @test */
    public function inbound_sms_with_tip_format_dispatches_job(): void
    {
        $response = $this->post('/webhooks/at/sms', [
            'from' => '+254711111111',
            'text' => 'TIP KE-2024-00042 I saw the child near Westgate Mall',
        ]);

        $response->assertNoContent();
        Queue::assertPushed(ProcessInboundTip::class, function ($job) {
            return $job->tip['refNo'] === 'KE-2024-00042'
                && $job->tip['from'] === '+254711111111';
        });
    }

    /** @test */
    public function inbound_sms_without_tip_prefix_dispatches_nothing(): void
    {
        $response = $this->post('/webhooks/at/sms', [
            'from' => '+254711111111',
            'text' => 'Hello, I need help',
        ]);

        $response->assertNoContent();
        Queue::assertNotPushed(ProcessInboundTip::class);
    }

    /** @test */
    public function inbound_sms_empty_body_dispatches_nothing(): void
    {
        $response = $this->post('/webhooks/at/sms', [
            'from' => '+254711111111',
            'text' => '',
        ]);

        $response->assertNoContent();
        Queue::assertNothingPushed();
    }

    // ── CSRF exemption ────────────────────────────────────────────────────────

    /** @test */
    public function webhook_routes_are_exempt_from_csrf(): void
    {
        // withoutMiddleware is not needed — webhook routes bypass CSRF globally.
        // This test confirms the route is reachable without CSRF token.
        $response = $this->post('/webhooks/at/ussd', [
            'sessionId'   => 'test',
            'serviceCode' => '*384#',
            'phoneNumber' => '+254700000001',
            'text'        => '',
        ]);

        // Should NOT return 419 (CSRF token mismatch)
        $response->assertStatus(200);
    }
}