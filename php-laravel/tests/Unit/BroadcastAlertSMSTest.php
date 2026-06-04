<?php

namespace Tests\Unit\Jobs;

use App\Jobs\BroadcastAlertSMS;
use App\Services\AfricasTalkingService;
use App\Services\AmberApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Mockery;

class BroadcastAlertSMSTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function job_has_correct_retry_count(): void
    {
        $job = new BroadcastAlertSMS('case-id', 'Nairobi');
        $this->assertEquals(3, $job->tries);
    }

    /** @test */
    public function job_has_backoff_configured(): void
    {
        $job = new BroadcastAlertSMS('case-id', 'Nairobi');
        $this->assertGreaterThan(0, $job->backoff);
    }

    /** @test */
    public function job_stores_case_id_and_county(): void
    {
        $job = new BroadcastAlertSMS('test-uuid', 'Mombasa');
        $this->assertEquals('test-uuid', $job->caseId);
        $this->assertEquals('Mombasa', $job->county);
    }

    /** @test */
    public function handle_does_nothing_when_api_returns_null(): void
    {
        $api = Mockery::mock(AmberApiClient::class);
        $api->shouldReceive('getCase')->once()->with('case-id')->andReturn(null);

        $at = Mockery::mock(AfricasTalkingService::class);
        $at->shouldNotReceive('sendAlert');

        $job = new BroadcastAlertSMS('case-id', 'Nairobi');
        $job->handle($at, $api);
    }

    /** @test */
    public function handle_does_nothing_when_no_subscribers(): void
    {
        // No subscribers in the database
        $api = Mockery::mock(AmberApiClient::class);
        $api->shouldReceive('getCase')->once()->andReturn([
            'id'            => 'case-id',
            'reference_no'  => 'KE-2024-00001',
            'child_name'    => 'Brian Otieno',
            'age'           => 8,
            'gender'        => 'male',
            'missing_since' => now()->toISOString(),
            'last_seen_area'=> 'Mathare',
        ]);

        $at = Mockery::mock(AfricasTalkingService::class);
        $at->shouldNotReceive('sendAlert');

        $job = new BroadcastAlertSMS('case-id', 'Nairobi');
        $job->handle($at, $api);
    }

    /** @test */
    public function handle_sends_sms_to_county_subscribers(): void
    {
        // Insert subscribers into the test database
        DB::table('alert_subscribers')->insert([
            ['phone' => '+254711111111', 'county' => 'Nairobi', 'opted_in_at' => now()],
            ['phone' => '+254722222222', 'county' => 'Nairobi', 'opted_in_at' => now()],
            ['phone' => '+254733333333', 'county' => 'Mombasa', 'opted_in_at' => now()], // different county
        ]);

        $case = [
            'id'            => 'case-uuid',
            'reference_no'  => 'KE-2024-00001',
            'child_name'    => 'Grace Wanjiku',
            'age'           => 7,
            'gender'        => 'female',
            'missing_since' => now()->subHours(3)->toISOString(),
            'last_seen_area'=> 'Kondele',
        ];

        $api = Mockery::mock(AmberApiClient::class);
        $api->shouldReceive('getCase')->once()->andReturn($case);

        $at = Mockery::mock(AfricasTalkingService::class);
        $at->shouldReceive('sendAlert')
            ->once()
            ->withArgs(function ($receivedCase, $phones) {
                // Only the 2 Nairobi subscribers, not the Mombasa one
                return count($phones) === 2
                    && in_array('+254711111111', $phones)
                    && in_array('+254722222222', $phones)
                    && !in_array('+254733333333', $phones);
            })
            ->andReturn(['sent' => 2, 'failed' => 0, 'ids' => ['id1', 'id2']]);

        $job = new BroadcastAlertSMS('case-uuid', 'Nairobi');
        $job->handle($at, $api);
    }

    /** @test */
    public function handle_excludes_opted_out_subscribers(): void
    {
        DB::table('alert_subscribers')->insert([
            ['phone' => '+254711111111', 'county' => 'Kisumu', 'opted_in_at' => now(), 'opted_out_at' => null],
            [
                'phone'        => '+254722222222',
                'county'       => 'Kisumu',
                'opted_in_at'  => now()->subDays(30),
                'opted_out_at' => now()->subDays(1), // opted out
            ],
        ]);

        $api = Mockery::mock(AmberApiClient::class);
        $api->shouldReceive('getCase')->andReturn([
            'id' => 'x', 'reference_no' => 'KE-2024-00001',
            'child_name' => 'Test', 'age' => 5, 'gender' => 'female',
            'missing_since' => now()->toISOString(), 'last_seen_area' => 'Kisumu',
        ]);

        $at = Mockery::mock(AfricasTalkingService::class);
        $at->shouldReceive('sendAlert')
            ->once()
            ->withArgs(function ($case, $phones) {
                return count($phones) === 1 && $phones[0] === '+254711111111';
            })
            ->andReturn(['sent' => 1, 'failed' => 0, 'ids' => ['id1']]);

        $job = new BroadcastAlertSMS('x', 'Kisumu');
        $job->handle($at, $api);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}