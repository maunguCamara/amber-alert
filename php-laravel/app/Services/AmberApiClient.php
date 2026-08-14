<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AlertApiContract;
use App\DTOs\CaseDetail;
use App\DTOs\CaseGeoPoint;
use App\DTOs\SubmitCaseData;
use App\Exceptions\ApiException;
use App\Exceptions\ApiNetworkException;
use App\Exceptions\ApiNotFoundException;
use App\Exceptions\ApiUnauthorizedException;
use App\Exceptions\ApiValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class AmberApiClient implements AlertApiContract
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('amber.api_url', 'http://localhost:8080'), '/');
        $this->timeout = (int) config('amber.api_timeout', 10);
    }

    public function getGeoPoints(int $limit = 500, ?string $county = null, ?string $status = null): array
    {
        $params = array_filter(
            ['limit' => $limit, 'county' => $county, 'status' => $status],
            fn ($v) => $v !== null
        );

        $data = $this->get('/api/v1/cases/map', $params);

        return array_map(
            static fn (array $item) => CaseGeoPoint::fromArray($item),
            (array) ($data['data'] ?? [])
        );
    }

    public function getCase(string $id): CaseDetail
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Case ID must not be empty');
        }
        return CaseDetail::fromArray($this->get("/api/v1/cases/{$id}"));
    }

    public function createCase(SubmitCaseData $data, string $apiToken): array
    {
        $this->assertToken($apiToken);
        $response = $this->post('/api/v1/cases', $data->toApiArray(), $apiToken);

        if (! isset($response['id'], $response['reference_no'])) {
            throw new ApiException('API returned case without id or reference_no', 500);
        }

        return [
            'id'           => (string) $response['id'],
            'reference_no' => (string) $response['reference_no'],
        ];
    }

    public function updateStatus(string $id, string $status, string $resolution, string $apiToken): void
    {
        $this->assertToken($apiToken);
        $this->patch("/api/v1/cases/{$id}/status", compact('status', 'resolution'), $apiToken);
    }

    public function broadcastCase(string $id, string $apiToken): void
    {
        $this->assertToken($apiToken);
        $this->post("/api/v1/admin/cases/{$id}/broadcast", [], $apiToken);
    }

    public function getStats(): array
    {
        try {
            $data = $this->get('/api/v1/admin/stats');
            return [
                'active'   => (int) ($data['active']   ?? 0),
                'review'   => (int) ($data['review']   ?? 0),
                'resolved' => (int) ($data['resolved'] ?? 0),
                'total'    => (int) ($data['total']    ?? 0),
            ];
        } catch (ApiException) {
            return ['active' => 0, 'review' => 0, 'resolved' => 0, 'total' => 0];
        }
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function get(string $path, array $query = [], ?string $token = null): array
    {
        return $this->send('GET', $path, $query, null, $token);
    }

    private function post(string $path, array $body, ?string $token = null): array
    {
        return $this->send('POST', $path, [], $body, $token);
    }

    private function patch(string $path, array $body, ?string $token = null): array
    {
        return $this->send('PATCH', $path, [], $body, $token);
    }

    private function send(string $method, string $path, array $query, ?array $body, ?string $token): array
    {
        $request = Http::timeout($this->timeout)->acceptJson();

        if ($token !== null) {
            $request = $request->withToken($token);
        }
        if (! empty($query)) {
            $request = $request->withQueryParameters($query);
        }

        try {
            $response = match ($method) {
                'GET'   => $request->get($this->baseUrl . $path),
                'POST'  => $request->asJson()->post($this->baseUrl . $path, $body ?? []),
                'PATCH' => $request->asJson()->patch($this->baseUrl . $path, $body ?? []),
                default => throw new \LogicException("Unsupported HTTP method: {$method}"),
            };
        } catch (ConnectionException $e) {
            Log::error('AmberApiClient: network error', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()]);
            throw new ApiNetworkException("Could not reach alert API ({$path}): {$e->getMessage()}", 0, $e);
        }

        return $this->handleResponse($response, $path);
    }

    private function handleResponse(Response $response, string $path): array
    {
        if ($response->successful()) {
            $json = $response->json();
            return is_array($json) ? $json : [];
        }

        $status = $response->status();
        $body   = $response->json() ?? [];

        Log::warning('AmberApiClient: non-success response', ['path' => $path, 'status' => $status, 'body' => $body]);

        throw match (true) {
            $status === 401,
            $status === 403 => new ApiUnauthorizedException((string) ($body['error'] ?? 'Unauthorized'), $status),
            $status === 404 => new ApiNotFoundException((string) ($body['error'] ?? 'Not found'), $status),
            $status === 422 => new ApiValidationException(
                (array) ($body['errors'] ?? ['general' => [$body['error'] ?? 'Validation failed']])
            ),
            default => new ApiException((string) ($body['error'] ?? "API error {$status}"), $status),
        };
    }

    public function login(string $email, string $password): array
    {
        return $this->post("/api/v1/auth/login", compact("email", "password"));
    }

    public function register(array $data): array
    {
        return $this->post("/api/v1/auth/register", $data);
    }

    private function assertToken(string $token): void
    {
        if (trim($token) === '') {
            throw new ApiUnauthorizedException('An API token is required for this operation', 401);
        }
    }
}