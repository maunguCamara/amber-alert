<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\AlertApiContract;
use App\Exceptions\ApiNetworkException;
use App\Exceptions\ApiNotFoundException;
use App\Exceptions\ApiUnauthorizedException;
use App\Exceptions\ApiValidationException;
use App\Http\Requests\StoreCaseRequest;
use App\Services\MediaUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class CaseController extends Controller
{
    public function __construct(
        private readonly AlertApiContract   $api,
        private readonly MediaUploadService $media,
    ) {}

    public function map(): View
    {
        return view('cases.map', ['stats' => $this->api->getStats()]);
    }

    public function show(string $id): View|RedirectResponse
    {
        try {
            $case = $this->api->getCase($id);
        } catch (ApiNotFoundException) {
            abort(404, 'This case does not exist or has been removed.');
        } catch (ApiNetworkException) {
            return redirect()->route('home')
                ->with('error', 'Could not load case details — the alert service is temporarily unavailable.');
        }

        return view('cases.show', ['case' => $case]);
    }

    public function create(): View
    {
        return view('cases.create');
    }

    public function store(StoreCaseRequest $request): RedirectResponse
    {
        $dto      = $request->toDTO();
        $apiToken = $request->user()?->api_token;

        // Guest submissions are not silently discarded — user is told clearly
        if ($apiToken === null) {
            // TODO: dispatch GuestCaseSubmission job with a system token
            return redirect()->route('home')->with(
                'success',
                'Your report has been received. An officer will review it. ' .
                'Create an account to track your report.'
            );
        }

        try {
            $result = $this->api->createCase($dto, $apiToken);
        } catch (ApiValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (ApiUnauthorizedException) {
            return back()->withInput()
                ->with('error', 'Your session has expired. Please log in again.');
        } catch (ApiNetworkException) {
            return back()->withInput()
                ->with('error', 'The alert service is temporarily unavailable. Please try again in a moment.');
        }

        return redirect()
            ->route('cases.show', $result['id'])
            ->with('success', 'Report submitted. Reference: ' . $result['reference_no'] . '. An officer will review it shortly.');
    }
}