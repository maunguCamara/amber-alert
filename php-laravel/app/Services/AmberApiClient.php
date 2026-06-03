<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper around the Go API.
 * All methods return decoded arrays or null on failure.
 */
class AmberApiClient
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('amber.api_url'), '/');
        $this->timeout = config('amber.api_timeout', 10);
    }

    // ── Cases ─────────────────────────────────────────────────────────────────

    public function getCase(string $id): ?array
    {
        return $this->get("/api/v1/cases/{$id}");
    }

    public function createCase(array $data, string $apiToken, ?string $photoUrl = null): ?array
    {
        if ($photoUrl) {
            $data['photo_url'] = $photoUrl;
        }
        return $this->post('/api/v1/cases', $data, $apiToken);
    }

    public function myCases(string $apiToken): array
    {
        return $this->get('/api/v1/cases', [], $apiToken)['data'] ?? [];
    }

    public function listAllCases(string $apiToken, array $filters = []): array
    {
        return $this->get('/api/v1/admin/cases', $filters, $apiToken)['data'] ?? [];
    }

    public function updateStatus(string $id, string $status, string $resolution, string $apiToken): bool
    {
        $result = $this->patch("/api/v1/cases/{$id}/status", compact('status', 'resolution'), $apiToken);
        return $result !== null;
    }

    public function broadcastCase(string $id, string $apiToken): bool
    {
        return $this->post("/api/v1/admin/cases/{$id}/broadcast", [], $apiToken) !== null;
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    public function listUsers(string $apiToken): array
    {
        return $this->get('/api/v1/admin/users', [], $apiToken)['data'] ?? [];
    }

    public function createOfficer(array $data, string $apiToken): ?array
    {
        return $this->post('/api/v1/admin/users', $data, $apiToken);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function getStats(): array
    {
        return $this->get('/api/v1/admin/stats') ?? ['active' => 0, 'review' => 0, 'resolved' => 0, 'total' => 0];
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function login(string $email, string $password): ?array
    {
        return $this->post('/api/v1/auth/login', compact('email', 'password'));
    }

    public function register(array $data): ?array
    {
        return $this->post('/api/v1/auth/register', $data);
    }

    public function refreshToken(string $refreshToken): ?array
    {
        return $this->post('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function get(string $path, array $query = [], ?string $token = null): ?array
    {
        try {
            $req = Http::timeout($this->timeout)->acceptJson();
            if ($token) {
                $req = $req->withToken($token);
            }
            $resp = $req->get($this->baseUrl . $path, $query);
            return $resp->successful() ? $resp->json() : null;
        } catch (\Exception $e) {
            Log::error('AmberApiClient GET failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function post(string $path, array $body = [], ?string $token = null): ?array
    {
        try {
            $req = Http::timeout($this->timeout)->acceptJson()->asJson();
            if ($token) {
                $req = $req->withToken($token);
            }
            $resp = $req->post($this->baseUrl . $path, $body);
            return $resp->successful() ? $resp->json() : null;
        } catch (\Exception $e) {
            Log::error('AmberApiClient POST failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function patch(string $path, array $body = [], ?string $token = null): ?array
    {
        try {
            $req = Http::timeout($this->timeout)->acceptJson()->asJson();
            if ($token) {
                $req = $req->withToken($token);
            }
            $resp = $req->patch($this->baseUrl . $path, $body);
            return $resp->successful() ? $resp->json() : null;
        } catch (\Exception $e) {
            Log::error('AmberApiClient PATCH failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }
}