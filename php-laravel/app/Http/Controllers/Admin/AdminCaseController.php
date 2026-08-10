<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AmberApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminCaseController extends Controller
{
    public function __construct(private readonly AmberApiClient $api) {}

    /**
     * GET /dashboard
     * Officer / admin case queue.
     */
    public function index()
    {
        $token = Auth::user()->api_token ?? '';

        // Fetch cases under review (pending officer approval)
        $pendingCases = $this->api->listAllCases($token, ['status' => 'review']) ?? [];

        // Fetch active cases for this officer's county
        $activeCases = $this->api->listAllCases($token, ['status' => 'active']) ?? [];

        $stats = $this->api->getStats();

        return view('admin.dashboard', compact('pendingCases', 'activeCases', 'stats'));
    }

    /**
     * GET /dashboard/cases/{id}
     */
    public function show(string $id)
    {
        $case = $this->api->getCase($id);
        if (! $case) abort(404);
        return view('admin.case_detail', compact('case'));
    }

    /**
     * PATCH /dashboard/cases/{id}/status
     * Officer approves, rejects, or resolves a case.
     */
    public function updateStatus(Request $request, string $id)
    {
        $request->validate([
            'status'     => 'required|in:active,review,resolved,closed',
            'resolution' => 'nullable|string|max:500',
        ]);

        $success = $this->api->updateStatus(
            id:         $id,
            status:     $request->status,
            resolution: $request->resolution ?? '',
            apiToken:   Auth::user()->api_token ?? '',
        );

        $label = match($request->status) {
            'active'   => 'approved and is now live on the map',
            'resolved' => 'marked as resolved',
            'closed'   => 'closed',
            default    => 'updated',
        };

        return back()->with(
            $success ? 'success' : 'error',
            $success ? "Case has been {$label}." : 'Failed to update case. Please try again.'
        );
    }

    /**
     * POST /dashboard/cases/{id}/broadcast
     * Trigger manual SMS blast for a case.
     */
    public function broadcast(string $id)
    {
        $this->api->broadcastCase($id, Auth::user()->api_token ?? '');
        return back()->with('success', 'SMS broadcast has been queued.');
    }
}