<?php

namespace Tests\Unit\Services;

use App\Services\AmberApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AmberApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['amber.api_url' => 'http://go-api:8080', 'amber.api_timeout' => 5]);
    }

    private function client(): AmberApiClient
    {
        return new AmberApiClient();
    }

    // ── getCase ───────────────────────────────────────────────────────────────

    /** @test */
    public function get_case_returns_array_on_success(): void
    {
        Http::fake([
            'go-api:8080/api/v1/cases/*' => Http::response([
                'id'           => 'abc-123',
                'child_name'   => 'Brian Otieno',
                'reference_no' => 'KE-2024-00001',
            ], 200),
        ]);

        $result = $this->client()->getCase('abc-123');

        $this->assertNotNull($result);
        $this->assertEquals('Brian Otieno', $result['child_name']);
    }

    /** @test */
    public function get_case_returns_null_on_404(): void
    {
        Http::fake([
            'go-api:8080/api/v1/cases/*' => Http::response(['error' => 'not found'], 404),
        ]);

        $result = $this->client()->getCase('does-not-exist');
        $this->assertNull($result);
    }

    /** @test */
    public function get_case_returns_null_on_network_error(): void
    {
        Http::fake([
            'go-api:8080/api/v1/cases/*' => Http::throw(new \Exception('Connection refused')),
        ]);

        $result = $this->client()->getCase('abc-123');
        $this->assertNull($result);
    }

    // ── createCase ────────────────────────────────────────────────────────────

    /** @test */
    public function create_case_posts_correct_payload(): void
    {
        Http::fake([
            'go-api:8080/api/v1/cases' => Http::response([
                'id'           => 'new-uuid',
                'reference_no' => 'KE-2024-00002',
            ], 201),
        ]);

        $data = [
            'child_name'        => 'Grace Wanjiku',
            'age'               => 7,
            'gender'            => 'female',
            'county'            => 'Kisumu',
            'lat'               => -0.092,
            'lng'               => 34.768,
            'clothing'          => 'Yellow dress',
            'description'       => 'Missing from market',
            'missing_since'     => now()->subHours(3)->toISOString(),
            'circumstance_type' => 'wandered',
            'reporter_type'     => 'public',
            'last_seen_area'    => 'Kondele Market',
        ];

        $result = $this->client()->createCase($data, 'test-token');

        $this->assertNotNull($result);
        $this->assertEquals('KE-2024-00002', $result['reference_no']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://go-api:8080/api/v1/cases'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['child_name'] === 'Grace Wanjiku';
        });
    }

    /** @test */
    public function create_case_returns_null_on_server_error(): void
    {
        Http::fake([
            'go-api:8080/api/v1/cases' => Http::response(['error' => 'server error'], 500),
        ]);

        $result = $this->client()->createCase(['child_name' => 'Test'], 'token');
        $this->assertNull($result);
    }

    // ── updateStatus ─────────────────────────────────────────────────────────

    /** @test */
    public function update_status_returns_true_on_success(): void
    {
        Http::fake([
            'go-api:8080/api/v1/cases/*/status' => Http::response(['message' => 'updated'], 200),
        ]);

        $result = $this->client()->updateStatus('case-id', 'active', '', 'token');
        $this->assertTrue($result);
    }

    /** @test */
    public function update_status_returns_false_on_failure(): void
    {
        Http::fake([
            'go-api:8080/api/v1/cases/*/status' => Http::response([], 500),
        ]);

        $result = $this->client()->updateStatus('case-id', 'active', '', 'token');
        $this->assertFalse($result);
    }

    // ── login ─────────────────────────────────────────────────────────────────

    /** @test */
    public function login_returns_tokens_on_success(): void
    {
        Http::fake([
            'go-api:8080/api/v1/auth/login' => Http::response([
                'access_token'  => 'eyJ...',
                'refresh_token' => 'eyR...',
                'user'          => ['role' => 'public'],
            ], 200),
        ]);

        $result = $this->client()->login('user@example.ke', 'Password123!');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
    }

    /** @test */
    public function login_returns_null_on_invalid_credentials(): void
    {
        Http::fake([
            'go-api:8080/api/v1/auth/login' => Http::response(['error' => 'invalid credentials'], 401),
        ]);

        $result = $this->client()->login('user@example.ke', 'wrong-pass');
        $this->assertNull($result);
    }

    // ── getStats ──────────────────────────────────────────────────────────────

    /** @test */
    public function get_stats_returns_defaults_on_failure(): void
    {
        Http::fake([
            'go-api:8080/api/v1/admin/stats' => Http::throw(new \Exception('timeout')),
        ]);

        $stats = $this->client()->getStats();

        $this->assertEquals(0, $stats['active']);
        $this->assertEquals(0, $stats['review']);
        $this->assertEquals(0, $stats['resolved']);
        $this->assertEquals(0, $stats['total']);
    }

    /** @test */
    public function get_stats_returns_data_on_success(): void
    {
        Http::fake([
            'go-api:8080/api/v1/admin/stats' => Http::response([
                'active'   => 5,
                'review'   => 2,
                'resolved' => 3,
                'total'    => 47,
            ], 200),
        ]);

        $stats = $this->client()->getStats();

        $this->assertEquals(5, $stats['active']);
        $this->assertEquals(47, $stats['total']);
    }

    // ── myCases ───────────────────────────────────────────────────────────────

    /** @test */
    public function my_cases_returns_empty_array_on_failure(): void
    {
        Http::fake([
            'go-api:8080/api/v1/cases' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $cases = $this->client()->myCases('bad-token');
        $this->assertIsArray($cases);
        $this->assertEmpty($cases);
    }
}