<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Africa's Talking integration.
 *
 * Handles:
 *  - Outbound SMS blast when a new case is activated
 *  - USSD session flow for field reporting without a smartphone
 *  - Inbound SMS tip parsing
 */
class AfricasTalkingService
{
    private string $apiKey;
    private string $username;
    private string $shortCode;
    private string $baseUrl = 'https://api.africastalking.com/version1';
    private string $sandboxUrl = 'https://api.sandbox.africastalking.com/version1';

    public function __construct()
    {
        $this->apiKey    = config('amber.at_api_key');
        $this->username  = config('amber.at_username');
        $this->shortCode = config('amber.at_short_code');
    }

    // ── Outbound SMS ──────────────────────────────────────────────────────────

    /**
     * Send an Amber Alert SMS to a list of recipients.
     * Africa's Talking accepts up to 500 numbers per request.
     *
     * @param  array  $case     Case array from the Go API
     * @param  array  $phones   ['+254711111111', ...]
     * @return array            ['sent' => n, 'failed' => n, 'messageIds' => [...]]
     */
    public function sendAlert(array $case, array $phones): array
    {
        $message = $this->buildAlertMessage($case);
        $chunks  = array_chunk($phones, 500);
        $sent    = 0;
        $failed  = 0;
        $ids     = [];

        foreach ($chunks as $chunk) {
            $result = $this->sendSMS($message, $chunk);
            foreach ($result['SMSMessageData']['Recipients'] ?? [] as $r) {
                if ($r['statusCode'] === 101) {
                    $sent++;
                    $ids[] = $r['messageId'];
                } else {
                    $failed++;
                    Log::warning('AT SMS failed', ['recipient' => $r['number'], 'status' => $r['status']]);
                }
            }
        }

        return compact('sent', 'failed', 'ids');
    }

    /**
     * Send a single informational SMS (e.g. case submission confirmation).
     */
    public function sendSingle(string $phone, string $message): bool
    {
        $result = $this->sendSMS($message, [$phone]);
        $recipients = $result['SMSMessageData']['Recipients'] ?? [];
        return !empty($recipients) && $recipients[0]['statusCode'] === 101;
    }

    private function sendSMS(string $message, array $phones): array
    {
        try {
            $resp = Http::withHeaders([
                'apiKey' => $this->apiKey,
                'Accept' => 'application/json',
            ])->asForm()->post($this->apiUrl('/messaging'), [
                'username' => $this->username,
                'to'       => implode(',', $phones),
                'message'  => $message,
                'from'     => $this->shortCode,
            ]);

            return $resp->json() ?? [];
        } catch (\Exception $e) {
            Log::error('AT sendSMS exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function buildAlertMessage(array $case): string
    {
        // Keep under 160 chars for a single SMS segment.
        $missing = \Carbon\Carbon::parse($case['missing_since'])->diffForHumans();
        return sprintf(
            "🚨 AMBER ALERT %s\n%s, age %d, %s\nMissing %s from %s\nInfo: amberalert.go.ke/cases/%s",
            $case['reference_no'],
            $case['child_name'],
            $case['age'],
            ucfirst($case['gender']),
            $missing,
            $case['last_seen_area'],
            $case['id'],
        );
    }

    // ── USSD ──────────────────────────────────────────────────────────────────

    /**
     * Handle an Africa's Talking USSD callback.
     *
     * Session state is stored in the cache keyed by sessionId.
     * USSD flow:
     *   1. Welcome → select: 1) Report  2) Nearby alerts  3) Hotline
     *   2a. Report → name → age → county → clothing → description → phone → confirm
     *   2b. Nearby  → shows 3 closest active cases by county (no GPS over USSD)
     *
     * @return string  The CON/END response string expected by Africa's Talking
     */
    public function handleUSSD(string $sessionId, string $serviceCode, string $phoneNumber, string $text): string
    {
        $steps  = $text === '' ? [] : explode('*', $text);
        $step   = count($steps);
        $last   = $steps[$step - 1] ?? '';

        // ─ Step 0: Main menu ─────────────────────────────────────────────────
        if ($step === 0 || $text === '') {
            return "CON Karibu Kenya Amber Alert\n" .
                   "Welcome to Kenya Amber Alert\n\n" .
                   "1. Ripoti mtoto (Report missing child)\n" .
                   "2. Tahadhari karibu nawe (Nearby alerts)\n" .
                   "3. Wasiliana na polisi (Contact police)";
        }

        $choice = $steps[0];

        // ─ Option 3: Police hotline ───────────────────────────────────────────
        if ($choice === '3') {
            return "END Kenya Police: 999 / 0800 722 203\nChild Helpline: 116 (free)";
        }

        // ─ Option 2: Nearby alerts ────────────────────────────────────────────
        if ($choice === '2') {
            $county  = $steps[1] ?? null;
            if (! $county) {
                return "CON Ingiza kaunti yako:\n(Enter your county e.g. Nairobi)";
            }
            // In production, call Go API GET /cases/map?county=...&status=active&limit=3
            return "END Tahadhari za karibu / Nearby alerts:\n" .
                   "1. Brian O., 8yrs - Mathare (2d)\n" .
                   "2. Grace W., 7yrs - Kondele (3d)\n" .
                   "Maelezo: amberalert.go.ke";
        }

        // ─ Option 1: Report flow ──────────────────────────────────────────────
        if ($choice === '1') {
            return match ($step) {
                1 => "CON Jina la mtoto / Child's name:",
                2 => "CON Umri wa mtoto / Child's age:",
                3 => "CON Kaunti / County (e.g. Nairobi):",
                4 => "CON Mavazi ya mtoto / Clothing worn:",
                5 => "CON Maelezo mafupi / Brief description:",
                6 => "CON Namba ya kuwasiliana / Contact phone:",
                7 => $this->confirmUSSDReport($phoneNumber, $steps),
                default => "END Hitilafu. Jaribu tena. / Error. Please try again.",
            };
        }

        return "END Chaguo batili / Invalid option. Dial again.";
    }

    private function confirmUSSDReport(string $reporterPhone, array $steps): string
    {
        // steps[1]=name, [2]=age, [3]=county, [4]=clothing, [5]=description, [6]=contactPhone
        $data = [
            'child_name'       => $steps[1] ?? 'Unknown',
            'age'              => (int) ($steps[2] ?? 0),
            'county'           => $steps[3] ?? 'Unknown',
            'clothing'         => $steps[4] ?? 'Unknown',
            'description'      => $steps[5] ?? '',
            'contact_phone'    => $steps[6] ?? $reporterPhone,
            'gender'           => 'unknown',
            'circumstance_type'=> 'unknown',
            'reporter_type'    => 'public',
            'last_seen_area'   => $steps[3] ?? 'Unknown',
            // Default coords for county centre — production maps county → lat/lng
            'lat'              => -1.286389,
            'lng'              => 36.817223,
            'missing_since'    => now()->toISOString(),
        ];

        // Queue an API call rather than doing HTTP inside the USSD response window
        dispatch(new \App\Jobs\SubmitUSSDCase($data, $reporterPhone));

        return "END Asante! Ripoti yako imepokelewa.\nThank you! Case submitted.\nRef will be sent via SMS.";
    }

    // ── Tip parsing from inbound SMS ──────────────────────────────────────────

    /**
     * Parse a free-text inbound SMS tip and attach it to the referenced case.
     * Format: "TIP KE-2024-00042 I saw the child near Westgate Mall"
     */
    public function parseInboundTip(string $from, string $message): ?array
    {
        if (! str_starts_with(strtoupper($message), 'TIP ')) {
            return null;
        }

        $parts   = explode(' ', $message, 3);
        $refNo   = strtoupper($parts[1] ?? '');
        $tipText = $parts[2] ?? '';

        if (empty($refNo) || empty($tipText)) {
            return null;
        }

        return compact('from', 'refNo', 'tipText');
    }

    private function apiUrl(string $path): string
    {
        $base = app()->environment('production') ? $this->baseUrl : $this->sandboxUrl;
        return $base . $path;
    }
}