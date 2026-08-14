<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AlertApiContract;
use App\Services\AmberApiClient;
use App\Services\MediaUploadService;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the interface to the concrete implementation.
        // Swap AmberApiClient for a mock in tests by rebinding in TestCase::setUp().
        $this->app->bind(AlertApiContract::class, AmberApiClient::class);

        // MediaUploadService has no interface yet — bind as singleton
        // since it holds no mutable state and is expensive to construct.
        $this->app->singleton(MediaUploadService::class);
    }

    public function boot(): void
    {
        //
    }
}