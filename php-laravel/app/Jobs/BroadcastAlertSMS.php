<?php

namespace App\Jobs;

use App\Services\AfricasTalkingService;
use App\Services\AmberApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched when a case is set to "active" status by an officer.
 *
 * Responsibilities:
 * - Fetch the full case from the Go API
 * - Build the alert SMS
 * - Send to all subscribers in the affected county via Africa's Talking
 * - Log delivery records back to Go API
 */
class BroadcastAlertSMS implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30; // seconds between retries

    public function __construct(
        public readonly string $caseId,
        public readonly string $county,
    ) {}

    public function handle(AfricasTalkingService $at, AmberApiClient $api): void
    {
        $case = $api->getCase($this->caseId);
        if (! $case) {
            Log::warning('BroadcastAlertSMS: case not found', ['id' => $this->caseId]);
            return;
        }

        // In production: pull subscriber phones from a geo-filtered DB query.
        // For now, fetch county subscribers from a local cache or table.
        $phones = $this->getCountySubscribers($this->county);

        if (empty($phones)) {
            Log::info('BroadcastAlertSMS: no subscribers', ['county' => $this->county]);
            return;
        }

        $result = $at->sendAlert($case, $phones);

        Log::info('BroadcastAlertSMS dispatched', [
            'case'   => $case['reference_no'],
            'county' => $this->county,
            'sent'   => $result['sent'],
            'failed' => $result['failed'],
        ]);
    }

    private function getCountySubscribers(string $county): array
    {
        // Query local `alert_subscribers` table for opted-in phones in this county.
        // Schema: id, phone, county, opted_in_at, opted_out_at (nullable)
        return \DB::table('alert_subscribers')
            ->where('county', $county)
            ->whereNull('opted_out_at')
            ->pluck('phone')
            ->toArray();
    }
}