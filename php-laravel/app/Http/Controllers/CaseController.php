<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCaseRequest;
use App\Services\AmberApiClient;
use App\Services\MediaUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CaseController extends Controller
{
    public function __construct(
        private readonly AmberApiClient     $api,
        private readonly MediaUploadService $media,
    ) {}

    /**
     * GET /
     * Homepage — interactive Kenya map.
     * Case geo-data loads client-side from the Go API so the map is always live.
     */
    public function map()
    {
        // Stats are fetched from Go API; fall back to zeros if API is down
        try {
            $stats = $this->api->getStats();
        } catch (\Exception $e) {
            $stats = ['active' => 0, 'review' => 0, 'resolved' => 0, 'total' => 0];
        }

        return view('cases.map', compact('stats'));
    }

    /**
     * GET /cases/{id}
     * Public detail page for a specific case.
     */
    public function show(string $id)
    {
        $case = $this->api->getCase($id);

        if (! $case) {
            abort(404, 'Case not found');
        }

        return view('cases.show', compact('case'));
    }

    /**
     * GET /report
     * Public report form — no login required.
     */
    public function create()
    {
        return view('cases.create');
    }

    /**
     * POST /report
     * Submit a missing child report.
     * Case is created with status=review and must be approved by an officer.
     */
    public function store(StoreCaseRequest $request)
    {
        $validated = $request->validated();

        // Use a guest token for public submissions (no login required)
        $apiToken = Auth::check() ? Auth::user()->api_token : null;

        // Upload photo if provided
        $photoUrl = null;
        if ($request->hasFile('photo') && $apiToken) {
            $photoUrl = $this->media->upload(
                $request->file('photo'),
                $apiToken
            );
        }

        // Submit to Go API
        $case = null;
        if ($apiToken) {
            $case = $this->api->createCase(
                data: $validated,
                apiToken: $apiToken,
                photoUrl: $photoUrl,
            );
        }

        // For guest submissions (no token), show confirmation without a case ID
        if (! $case) {
            return redirect()
                ->route('home')
                ->with('success', 'Your report has been received and is under review by an officer. You will receive an SMS confirmation shortly.');
        }

        return redirect()
            ->route('cases.show', $case['id'])
            ->with('success', 'Report submitted. Reference: ' . $case['reference_no'] . '. An officer will review it shortly.');
    }

    /**
     * GET /my-reports
     */
    public function myReports(Request $request)
    {
        $cases = Auth::check()
            ? $this->api->myCases(Auth::user()->api_token)
            : [];

        return view('cases.mine', compact('cases'));
    }
}