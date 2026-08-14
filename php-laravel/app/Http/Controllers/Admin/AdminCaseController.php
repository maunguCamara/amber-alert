<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\AlertApiContract;
use App\Enums\CaseStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ApiNetworkException;
use App\Exceptions\ApiNotFoundException;
use App\Exceptions\ApiUnauthorizedException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class AdminCaseController extends Controller
{
    public function __construct(private readonly AlertApiContract $api) {}

    public function index(): View
    {
        $token = $this->requireToken();

        // Catch independently so one failure doesn't blank the whole dashboard
        try {
            $pendingCases = $this->api->getGeoPoints(limit: 100, status: CaseStatus::Review->value);
        } catch (ApiException) {
            $pendingCases = [];
        }

        try {
            $activeCases = $this->api->getGeoPoints(limit: 200, status: CaseStatus::Active->value);
        } catch (ApiException) {
            $activeCases = [];
        }

        $stats = $this->api->getStats(); // never throws

        return view('admin.dashboard', compact('pendingCases', 'activeCases', 'stats'));
    }

    public function show(string $id): View|RedirectResponse
    {
        try {
            $case = $this->api->getCase($id);
        } catch (ApiNotFoundException) {
            abort(404);
        } catch (ApiNetworkException) {
            return redirect()->route('dashboard.index')
                ->with('error', 'Could not load case — service temporarily unavailable.');
        }

        return view('admin.case_detail', ['case' => $case]);
    }

    public function updateStatus(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'status'     => ['required', 'string', 'in:active,review,resolved,closed'],
            'resolution' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = CaseStatus::from($validated['status']);

        // Fetch current case to enforce valid transition
        try {
            $case = $this->api->getCase($id);
        } catch (ApiNotFoundException) {
            return back()->with('error', 'Case not found.');
        }

        if (! $case->status->canTransitionTo($newStatus)) {
            return back()->with(
                'error',
                "Cannot change status from {$case->status->label()} to {$newStatus->label()}."
            );
        }

        try {
            $this->api->updateStatus(
                id:         $id,
                status:     $newStatus->value,
                resolution: (string) ($validated['resolution'] ?? ''),
                apiToken:   $this->requireToken(),
            );
        } catch (ApiUnauthorizedException) {
            return back()->with('error', 'Your session has expired. Please log in again.');
        } catch (ApiException $e) {
            return back()->with('error', 'Could not update case: ' . $e->getMessage());
        }

        return back()->with('success', "Case has been {$newStatus->label()}.");
    }

    public function broadcast(string $id): RedirectResponse
    {
        try {
            $this->api->broadcastCase($id, $this->requireToken());
        } catch (ApiUnauthorizedException) {
            return back()->with('error', 'Your session has expired. Please log in again.');
        } catch (ApiException $e) {
            return back()->with('error', 'SMS broadcast failed: ' . $e->getMessage());
        }

        return back()->with('success', 'SMS broadcast queued for this county.');
    }

    /**
     * Retrieve the authenticated user's API token.
     * Throws if the user is not authenticated or the token is empty.
     *
     * @throws ApiUnauthorizedException
     */
    private function requireToken(): string
    {
        $token = Auth::user()?->api_token ?? '';
        if (trim($token) === '') {
            throw new ApiUnauthorizedException('No API token — please log in again.', 401);
        }
        return $token;
    }
}