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
     * Renders the interactive map. Case geo-data is loaded client-side
     * via the Go API so the map is always real-time.
     */
    public function map()
    {
        $stats = $this->api->getStats();

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
     */
    public function create()
    {
        return view('cases.create');
    }

    /**
     * POST /report
     */
    public function store(StoreCaseRequest $request)
    {
        $validated = $request->validated();

        // Upload photo to the Go API (which proxies to S3)
        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->media->upload(
                $request->file('photo'),
                Auth::user()->api_token
            );
        }

        $case = $this->api->createCase(
            data: $validated,
            apiToken: Auth::user()->api_token,
            photoUrl: $photoUrl,
        );

        if (! $case) {
            return back()
                ->withInput()
                ->with('error', __('messages.case_submit_failed'));
        }

        return redirect()
            ->route('cases.show', $case['id'])
            ->with('success', __('messages.case_submitted', ['ref' => $case['reference_no']]));
    }

    /**
     * GET /my-reports
     */
    public function myReports(Request $request)
    {
        $cases = $this->api->myCases(Auth::user()->api_token);

        return view('cases.mine', compact('cases'));
    }
}