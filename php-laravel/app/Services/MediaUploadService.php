<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class MediaUploadService
{
    public function upload(UploadedFile $file, string $apiToken): ?string
    {
        // For local dev, just return a placeholder URL
        // In production this sends the file to the Go API
        return null;
    }
}
