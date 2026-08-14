<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiNetworkException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MediaUploadService
{
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('amber.api_url', 'http://localhost:8080'), '/');
        $this->timeout = 30; // photos can take longer than regular API calls
    }

    /**
     * Upload a photo to the Go API which stores it in S3/MinIO.
     *
     * @return string The public URL of the uploaded photo
     * @throws ApiNetworkException if the upload fails
     */
    public function upload(UploadedFile $file, string $apiToken, string $caseId): string
    {
        if (trim($apiToken) === '') {
            throw new \InvalidArgumentException('An API token is required to upload photos.');
        }

        if (! $file->isValid()) {
            throw new \InvalidArgumentException('The uploaded file is not valid: ' . $file->getErrorMessage());
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($apiToken)
                ->attach('photo', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                ->post("{$this->baseUrl}/api/v1/cases/{$caseId}/photos", [
                    'is_primary' => 'true',
                ]);
        } catch (ConnectionException $e) {
            Log::error('MediaUploadService: upload failed', ['error' => $e->getMessage()]);
            throw new ApiNetworkException('Photo upload failed: ' . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            Log::warning('MediaUploadService: non-success response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new ApiNetworkException("Photo upload returned HTTP {$response->status()}");
        }

        $data = $response->json();

        if (! isset($data['url'])) {
            throw new ApiNetworkException('Photo upload response missing URL field.');
        }

        return (string) $data['url'];
    }
}