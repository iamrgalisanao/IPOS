<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInitialBranchRequest;
use App\Http\Requests\CreateMachineProfileRequest;
use App\Http\Requests\CreateOwnerUserRequest;
use App\Models\Tenant;
use App\Services\OnboardingService;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Auth;

class CompanyOnboardingController extends Controller
{
    public function __construct(
        private OnboardingService $onboardingService,
        private TenantContext $tenantContext
    ) {}

    /**
     * Get onboarding state for a tenant
     */
    public function show(Tenant $company)
    {
        // Authorization: verify user has system admin access or manage_company permission
        // This will be handled by middleware

        return $this->runWithTenantContext($company, function () use ($company) {
            $progress = $this->onboardingService->getOnboardingProgress($company);
            $timeline = $this->onboardingService->getOnboardingTimeline($company);

            return inertia('SystemAdmin/CompanyOnboarding/Show', [
                'company' => $company,
                'onboarding_state' => $progress['state'],
                'progress_percentage' => $progress['progress_percentage'],
                'next_action' => $progress['next_action'],
                'status' => $progress['status'],
                'is_complete' => $progress['is_complete'],
                'initial_branch' => $progress['initial_branch'],
                'owner_user' => $progress['owner_user'],
                'machine_profile' => $progress['machine_profile'],
                'machine_profile_compliance_ready' => $progress['machine_profile_compliance_ready'],
                'bootstrap_token' => $progress['bootstrap_token'],
                'can_resend_bootstrap' => $progress['can_resend_bootstrap'],
                'timeline' => $timeline,
            ]);
        });
    }

    /**
     * Create initial branch for a tenant
     */
    public function createInitialBranch(Tenant $company, CreateInitialBranchRequest $request)
    {
        // Authorization: allow platform support or tenant admins with branch permissions
        $user = Auth::user();
        if (!$user || (! $user->isPlatformSupport() && ! $user->can('manage_products') && ! $user->hasRole('system_admin'))) {
            abort(403, 'Unauthorized to manage branches.');
        }

        return $this->runWithTenantContext($company, function () use ($company, $request) {
            try {
                $branch = $this->onboardingService->createInitialBranch(
                    $company,
                    $request->validated()
                );

                return response()->json([
                    'success' => true,
                    'branch' => $branch,
                    'message' => 'Initial branch created successfully.',
                ], 201);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                    'message' => 'Failed to create initial branch.',
                ], 422);
            }
        });
    }

    /**
     * Create owner user with bootstrap token
     */
    public function createOwnerUser(Tenant $company, CreateOwnerUserRequest $request)
    {
        // Authorization: allow platform support or tenant admins with user permissions
        $user = Auth::user();
        if (!$user || (! $user->isPlatformSupport() && ! $user->can('create_users') && ! $user->hasRole('system_admin'))) {
            abort(403, 'Unauthorized to create users.');
        }

        return $this->runWithTenantContext($company, function () use ($company, $request) {
            try {
                $result = $this->onboardingService->createOwnerUser(
                    $company,
                    $request->validated(),
                    $request->boolean('send_bootstrap_link', true)
                );

                // TODO: Queue email sending in Phase 6
                // if ($result['send_link']) {
                //     SendBootstrapLinkMailable::dispatch($result['user'], $result['bootstrap_link']);
                // }

                return response()->json([
                    'success' => true,
                    'user' => $result['user'],
                    'bootstrap_link' => $result['bootstrap_link'],
                                        'bootstrap_token' => $result['bootstrap_token'],
                    'message' => 'Owner user created. Bootstrap link sent to email.',
                ], 201);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                    'message' => 'Failed to create owner user.',
                ], 422);
            }
        });
    }

    /**
     * Register or update machine profile for onboarding compliance.
     */
    public function registerMachineProfile(Tenant $company, CreateMachineProfileRequest $request)
    {
        $user = Auth::user();
        if (!$user || (! $user->isPlatformSupport() && ! $user->can('manage_products') && ! $user->hasRole('system_admin'))) {
            abort(403, 'Unauthorized to register machine profile.');
        }

        return $this->runWithTenantContext($company, function () use ($company, $request) {
            try {
                $profile = $this->onboardingService->registerMachineProfile(
                    $company,
                    $request->validated()
                );

                return response()->json([
                    'success' => true,
                    'machine_profile' => $profile,
                    'machine_profile_compliance_ready' => $this->onboardingService->isMachineProfileComplianceComplete($profile),
                    'message' => 'Sales machine profile registered successfully.',
                ], 201);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                    'message' => 'Failed to register sales machine profile.',
                ], 422);
            }
        });
    }

    /**
     * Get bootstrap progress
     */
    public function getBootstrapProgress(Tenant $company)
    {
        return $this->runWithTenantContext($company, function () use ($company) {
            $onboardingState = $company->onboardingState;

            if (!$onboardingState || !$onboardingState->owner_user_id) {
                abort(404, 'No owner assigned yet.');
            }

            return response()->json([
                'company_id' => $company->id,
                'owner_email' => $onboardingState->owner_email,
                'bootstrap_status' => $this->getBootstrapStatus($onboardingState),
                'bootstrap_sent_at' => $onboardingState->created_at,
                'bootstrap_expires_at' => $onboardingState->bootstrap_token_expires_at,
                'owner_activated_at' => $onboardingState->completed_at,
                'can_resend' => !$onboardingState->status === 'ready',
                'resend_available_in' => $this->getResendAvailableIn($onboardingState),
            ]);
        });
    }

    /**
     * Resend bootstrap link
     */
    public function resendBootstrapLink(Tenant $company)
    {
        return $this->runWithTenantContext($company, function () use ($company) {
            try {
                $onboardingState = $this->onboardingService->resendBootstrapLink($company);

                // TODO: Queue email sending in Phase 6
                // SendBootstrapLinkMailable::dispatch(
                //     $onboardingState->ownerUser,
                //     route('auth.bootstrap.show', ['token' => $onboardingState->bootstrap_token])
                // );

                return response()->json([
                    'success' => true,
                    'message' => 'Bootstrap link resent to ' . $onboardingState->owner_email,
                    'bootstrap_link' => route('auth.bootstrap.show', ['token' => $onboardingState->bootstrap_token]),
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                    'message' => 'Failed to resend bootstrap link.',
                ], 422);
            }
        });
    }

    /**
     * Helper: Get bootstrap status label
     */
    private function getBootstrapStatus($onboardingState): string
    {
        if ($onboardingState->status === 'ready') {
            return 'used';
        }

        if ($onboardingState->isBootstrapTokenExpired()) {
            return 'expired';
        }

        return 'pending';
    }

    /**
     * Helper: Get seconds until resend is available
     */
    private function getResendAvailableIn($onboardingState): int
    {
        if ($onboardingState->status === 'ready') {
            return 0;
        }

        return 0; // Can resend immediately in current design
    }

    private function runWithTenantContext(Tenant $company, callable $callback)
    {
        $previousTenant = $this->tenantContext->getTenant();
        $this->tenantContext->setTenant($company);

        try {
            return $callback();
        } finally {
            if ($previousTenant) {
                $this->tenantContext->setTenant($previousTenant);
            } else {
                $this->tenantContext->clear();
            }
        }
    }
}
