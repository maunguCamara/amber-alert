<?php

namespace Tests\Unit\Services;

use App\Services\AfricasTalkingService;
use Tests\TestCase;
use Mockery;

class AfricasTalkingServiceTest extends TestCase
{
    private AfricasTalkingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set required config values for the service
        config([
            'amber.at_api_key'    => 'test-api-key',
            'amber.at_username'   => 'sandbox',
            'amber.at_short_code' => '22384',
        ]);

        $this->service = new AfricasTalkingService();
    }

    // ── buildAlertMessage (via sendAlert reflection) ──────────────────────────

    /** @test */
    public function alert_message_contains_reference_number(): void
    {
        $msg = $this->buildAlertMessage([
            'reference_no'  => 'KE-2024-00042',
            'child_name'    => 'Brian Otieno',
            'age'           => 8,
            'gender'        => 'male',
            'missing_since' => now()->subDays(2)->toISOString(),
            'last_seen_area'=> 'Mathare',
            'id'            => 'abc-123',
        ]);

        $this->assertStringContainsString('KE-2024-00042', $msg);
    }

    /** @test */
    public function alert_message_contains_child_name(): void
    {
        $msg = $this->buildAlertMessage([
            'reference_no'  => 'KE-2024-00001',
            'child_name'    => 'Grace Wanjiku',
            'age'           => 7,
            'gender'        => 'female',
            'missing_since' => now()->subHours(5)->toISOString(),
            'last_seen_area'=> 'Kondele',
            'id'            => 'def-456',
        ]);

        $this->assertStringContainsString('Grace Wanjiku', $msg);
    }

    /** @test */
    public function alert_message_is_under_160_chars(): void
    {
        $msg = $this->buildAlertMessage([
            'reference_no'  => 'KE-2024-00099',
            'child_name'    => 'A B',
            'age'           => 5,
            'gender'        => 'male',
            'missing_since' => now()->subHour()->toISOString(),
            'last_seen_area'=> 'X',
            'id'            => 'g',
        ]);

        $this->assertLessThanOrEqual(
            160,
            mb_strlen($msg),
            "SMS message exceeds 160 character single-segment limit"
        );
    }

    /** @test */
    public function alert_message_contains_portal_link(): void
    {
        $msg = $this->buildAlertMessage([
            'reference_no'  => 'KE-2024-00001',
            'child_name'    => 'Test',
            'age'           => 6,
            'gender'        => 'female',
            'missing_since' => now()->toISOString(),
            'last_seen_area'=> 'Nairobi',
            'id'            => 'uuid-here',
        ]);

        $this->assertStringContainsString('amberalert.go.ke', $msg);
    }

    // ── parseInboundTip ───────────────────────────────────────────────────────

    /** @test */
    public function parse_inbound_tip_valid_format(): void
    {
        $result = $this->service->parseInboundTip(
            '+254711111111',
            'TIP KE-2024-00042 I saw the child near Westgate Mall at 3pm'
        );

        $this->assertNotNull($result);
        $this->assertEquals('KE-2024-00042', $result['refNo']);
        $this->assertEquals('+254711111111', $result['from']);
        $this->assertEquals('I saw the child near Westgate Mall at 3pm', $result['tipText']);
    }

    /** @test */
    public function parse_inbound_tip_case_insensitive_prefix(): void
    {
        $result = $this->service->parseInboundTip(
            '+254711111111',
            'tip KE-2024-00001 child seen at bus station'
        );

        // Our implementation uses str_starts_with(strtoupper(...), 'TIP ')
        $this->assertNotNull($result);
    }

    /** @test */
    public function parse_inbound_tip_no_tip_prefix_returns_null(): void
    {
        $result = $this->service->parseInboundTip('+254711111111', 'Hello, who is this?');
        $this->assertNull($result);
    }

    /** @test */
    public function parse_inbound_tip_missing_reference_returns_null(): void
    {
        // "TIP" with only one word after — no message body
        $result = $this->service->parseInboundTip('+254711111111', 'TIP ');
        $this->assertNull($result);
    }

    /** @test */
    public function parse_inbound_tip_empty_message_returns_null(): void
    {
        $result = $this->service->parseInboundTip('+254711111111', '');
        $this->assertNull($result);
    }

    // ── USSD flow ─────────────────────────────────────────────────────────────

    /** @test */
    public function ussd_empty_text_returns_main_menu(): void
    {
        $response = $this->service->handleUSSD('sess-1', '*384#', '+254700000001', '');

        $this->assertStringStartsWith('CON', $response);
        $this->assertStringContainsString('Amber Alert', $response);
        $this->assertStringContainsString('1.', $response);
        $this->assertStringContainsString('2.', $response);
        $this->assertStringContainsString('3.', $response);
    }

    /** @test */
    public function ussd_option_3_returns_police_contacts(): void
    {
        $response = $this->service->handleUSSD('sess-2', '*384#', '+254700000002', '3');

        $this->assertStringStartsWith('END', $response);
        $this->assertStringContainsString('999', $response);
    }

    /** @test */
    public function ussd_option_2_without_county_prompts_county_input(): void
    {
        $response = $this->service->handleUSSD('sess-3', '*384#', '+254700000003', '2');

        $this->assertStringStartsWith('CON', $response);
        $this->assertStringContainsString('county', strtolower($response));
    }

    /** @test */
    public function ussd_option_2_with_county_returns_nearby_alerts(): void
    {
        $response = $this->service->handleUSSD('sess-4', '*384#', '+254700000004', '2*Nairobi');

        $this->assertStringStartsWith('END', $response);
    }

    /** @test */
    public function ussd_report_flow_step1_asks_child_name(): void
    {
        $response = $this->service->handleUSSD('sess-5', '*384#', '+254700000005', '1');

        $this->assertStringStartsWith('CON', $response);
        // Should ask for child's name
        $this->assertMatchesRegularExpression('/name|jina/i', $response);
    }

    /** @test */
    public function ussd_report_flow_step2_asks_age(): void
    {
        $response = $this->service->handleUSSD('sess-5', '*384#', '+254700000005', '1*Brian Otieno');

        $this->assertStringStartsWith('CON', $response);
        $this->assertMatchesRegularExpression('/age|umri/i', $response);
    }

    /** @test */
    public function ussd_report_flow_step7_submits_and_ends_session(): void
    {
        $text = '1*Brian*8*Nairobi*Blue uniform*Last seen at school*+254711111111';
        $response = $this->service->handleUSSD('sess-6', '*384#', '+254700000006', $text);

        $this->assertStringStartsWith('END', $response);
        $this->assertMatchesRegularExpression('/asante|thank/i', $response);
    }

    /** @test */
    public function ussd_invalid_option_returns_error(): void
    {
        $response = $this->service->handleUSSD('sess-7', '*384#', '+254700000007', '9');

        $this->assertStringStartsWith('END', $response);
    }

    // ── Helper: access private buildAlertMessage via reflection ───────────────

    private function buildAlertMessage(array $case): string
    {
        $ref = new \ReflectionClass($this->service);
        $method = $ref->getMethod('buildAlertMessage');
        $method->setAccessible(true);
        return $method->invoke($this->service, $case);
    }
}