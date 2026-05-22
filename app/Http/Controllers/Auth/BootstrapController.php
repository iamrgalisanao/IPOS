<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteBootstrapRequest;
use App\Models\CompanyOnboardingState;
use App\Services\OnboardingService;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __construct(private OnboardingService $onboardingService) {}

    /**
     * Show bootstrap form for a token
     */
    public function show(string $token)
    {
        // Validate token
        $onboardingState = $this->onboardingService->validateBootstrapToken($token);

        if (!$onboardingState) {
            return inertia('Auth/Bootstrap', [
                'valid' => false,
                'error' => 'Invalid or expired bootstrap link. Please contact your system administrator.',
                'token' => null,
            ]);
        }

        // Check if already completed
        if ($onboardingState->status === 'ready' && !$onboardingState->bootstrap_token) {
            return inertia('Auth/Bootstrap', [
                'valid' => false,
                'error' => 'This company has already been set up. Please log in.',
                'token' => null,
            ]);
        }

        // Return form with pre-filled data
        return inertia('Auth/Bootstrap', [
            'valid' => true,
            'owner_name' => $onboardingState->ownerUser ? 
                $onboardingState->ownerUser->first_name . ' ' . $onboardingState->ownerUser->last_name : 
                '',
            'company_name' => $onboardingState->tenant->name ?? '',
            'initial_branch_name' => $onboardingState->initialBranch->name ?? '',
            'token' => $token,
            'error' => null,
        ]);
    }

    /**
     * Complete bootstrap and set password
     */
    public function complete(string $token, CompleteBootstrapRequest $request)
    {
        try {
            $user = $this->onboardingService->completeBootstrap(
                $token,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Setup complete. Please log in with your email and password.',
                'redirect_to' => route('login'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Record bootstrap failure for rate limiting
            $this->onboardingService->recordBootstrapFailure($token);

            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => 'Failed to complete bootstrap.',
            ], 422);
        } catch (\Exception $e) {
            // Record bootstrap failure
            $this->onboardingService->recordBootstrapFailure($token);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during bootstrap completion.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
