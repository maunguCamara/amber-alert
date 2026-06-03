<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\AfricasTalkingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Handles USSD sessions from Africa's Talking.
 * Route: POST /webhooks/at/ussd
 *
 * Africa's Talking expects a plain-text response starting with CON (continue)
 * or END (terminate session).
 */
class USSDController extends Controller
{
    public function __construct(private readonly AfricasTalkingService $at) {}

    public function handle(Request $request): Response
    {
        $sessionId   = $request->input('sessionId', '');
        $serviceCode = $request->input('serviceCode', '');
        $phoneNumber = $request->input('phoneNumber', '');
        $text        = $request->input('text', '');

        $response = $this->at->handleUSSD($sessionId, $serviceCode, $phoneNumber, $text);

        // Africa's Talking requires Content-Type: text/plain
        return response($response, 200)->header('Content-Type', 'text/plain');
    }
}