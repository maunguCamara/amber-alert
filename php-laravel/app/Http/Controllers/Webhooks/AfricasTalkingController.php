<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundTip;
use App\Services\AfricasTalkingService;
use App\Services\AmberApiClient;
use Illuminate\Http\Request;

class AfricasTalkingController extends Controller
{
    public function __construct(
        private readonly AfricasTalkingService $at,
        private readonly AmberApiClient        $api,
    ) {}

    /**
     * POST /webhooks/at/delivery
     * Africa's Talking delivery status callback.
     * Forwards the messageId and status to the Go API for record-keeping.
     */
    public function deliveryReceipt(Request $request)
    {
        // No validation needed — the Go API is the source of truth for delivery state.
        // Laravel just proxies to keep the AT-facing URL on the PHP domain.
        $messageId = $request->input('id');
        $status    = $request->input('status');

        if ($messageId) {
            // Fire-and-forget to Go API delivery endpoint
            dispatch(fn () => $this->api->post('/api/v1/webhooks/at/delivery', compact('messageId', 'status')));
        }

        return response()->noContent();
    }

    /**
     * POST /webhooks/at/sms
     * Inbound SMS — members of public can text tips in the format:
     *   "TIP KE-2024-00042 I saw the child near Westgate Mall"
     */
    public function inboundSMS(Request $request)
    {
        $from    = $request->input('from');
        $message = $request->input('text', '');

        $tip = $this->at->parseInboundTip($from, $message);

        if ($tip) {
            ProcessInboundTip::dispatch($tip);
        }

        return response()->noContent();
    }
}