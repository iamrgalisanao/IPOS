<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CompanyOnboardingEvent;
use App\Models\CompanyOnboardingState;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    /**
     * Initialize onboarding state for a newly provisioned tenant
     */
    public function initializeOnboardingState(Tenant $tenant): CompanyOnboardingState
    {
        return DB::transaction(function () use ($tenant) {
            // Check if onboarding state already exists
            $existing = CompanyOnboardingState::where('tenant_id', $tenant->id)->first();
            if ($existing) {
                return $existing;
            }

            // Create new onboarding state
            $onboardingState = CompanyOnboardingState::create([
                'tenant_id' => $tenant->id,
                'status' => 'provisioned',
            ]);

            // Record initialization event
            $this->recordOnboardingEvent($tenant->id, 'onboarding_initialized', [
                'tenant_name' => $tenant->name,
                'tenant_id' => $tenant->id,
            ]);

            return $onboardingState;
        });
    }

    /**
     * Create initial branch for tenant
     */
    public function createInitialBranch(Tenant $tenant, array $branchData): Branch
    {
        return DB::transaction(function () use ($tenant, $branchData) {
            // Get or initialize onboarding state
            $onboardingState = CompanyOnboardingState::where('tenant_id', $tenant->id)->first();
            if (!$onboardingState) {
                $onboardingState = $this->initializeOnboardingState($tenant);
            }

            // Prevent duplicate branch creation
            if ($onboardingState->initial_branch_id) {
                throw ValidationException::withMessages([
                    'branch' => ['Initial branch already created for this tenant.'],
                ]);
            }

            // Validate branch code uniqueness globally
            $existingCode = Branch::where('branch_code', $branchData['branch_code'])->first();
            if ($existingCode) {
                throw ValidationException::withMessages([
                    'branch_code' => ['Branch code already in use.'],
                ]);
            }

            // Create branch
            $branch = Branch::create([
                'tenant_id' => $tenant->id,
                'name' => $branchData['branch_name'] ?? $branchData['name'],
                'branch_code' => $branchData['branch_code'],
                'location' => $branchData['location'] ?? null,
                'status' => 'active',
            ]);

            // Update onboarding state
            $onboardingState->update([
                'initial_branch_id' => $branch->id,
                'status' => 'branch_created',
            ]);

            // Record event
            $this->recordOnboardingEvent($tenant->id, 'branch_created', [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'branch_code' => $branch->branch_code,
            ]);

            return $branch;
        });
    }

    /**
     * Create owner user and generate bootstrap token
     */
    public function createOwnerUser(Tenant $tenant, array $userData, bool $sendLink = true): array
    {
        return DB::transaction(function () use ($tenant, $userData, $sendLink) {
            // Get or initialize onboarding state
            $onboardingState = CompanyOnboardingState::where('tenant_id', $tenant->id)->first();
            if (!$onboardingState) {
                $onboardingState = $this->initializeOnboardingState($tenant);
            }

            // Verify branch has been created
            if (!$onboardingState->initial_branch_id) {
                throw ValidationException::withMessages([
                    'branch' => ['Please create the initial branch first.'],
                ]);
            }

            // Prevent duplicate owner creation
            if ($onboardingState->owner_user_id) {
                throw ValidationException::withMessages([
                    'owner' => ['Owner user already assigned for this tenant.'],
                ]);
            }

            // Validate email uniqueness globally
            $existingEmail = User::where('email', $userData['email'])->first();
            if ($existingEmail) {
                throw ValidationException::withMessages([
                    'email' => ['Email already in use by another user.'],
                ]);
            }

            // Create owner user (password will be set during bootstrap)
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $userData['first_name'] . ' ' . $userData['last_name'],
                'email' => $userData['email'],
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'password' => \Illuminate\Support\Facades\Hash::make(null),
                'phone' => $userData['phone'] ?? null,
                'status' => 'pending_activation',
                'email_verified_at' => null,
            ]);

            // Assign Owner role
            $ownerRole = Role::where('name', 'Owner')->where('tenant_id', $tenant->id)->first();
            if ($ownerRole) {
                $user->roles()->attach($ownerRole->id);
            }

            // Generate bootstrap token
            $bootstrapToken = $this->generateBootstrapToken();
            $expiresAt = now()->addDays(7);

            // Update onboarding state
            $onboardingState->update([
                'owner_user_id' => $user->id,
                'owner_email' => $user->email,
                'bootstrap_token' => $bootstrapToken,
                'bootstrap_token_expires_at' => $expiresAt,
                'bootstrap_attempts' => 0,
                'status' => 'owner_assigned',
            ]);

            // Record events
            $this->recordOnboardingEvent($tenant->id, 'owner_created', [
                'user_id' => $user->id,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
            ]);

            $this->recordOnboardingEvent($tenant->id, 'bootstrap_token_generated', [
                'user_id' => $user->id,
                'expires_at' => $expiresAt->toDateTimeString(),
            ]);

            return [
                'user' => $user,
                'bootstrap_token' => $bootstrapToken,
                'bootstrap_link' => '#', // TODO: Implement auth.bootstrap.show route in Phase 6
                'expires_at' => $expiresAt,
                'send_link' => $sendLink,
            ];
        });
    }

    /**
     * Register or update sales machine profile during onboarding.
     */
    public function registerMachineProfile(Tenant $tenant, array $profileData): SalesMachineProfile
    {
        return DB::transaction(function () use ($tenant, $profileData) {
            $onboardingState = CompanyOnboardingState::where('tenant_id', $tenant->id)->first();
            if (!$onboardingState) {
                $onboardingState = $this->initializeOnboardingState($tenant);
            }

            if (!$onboardingState->initial_branch_id) {
                throw ValidationException::withMessages([
                    'branch' => ['Please create the initial branch first.'],
                ]);
            }

            if (!$onboardingState->owner_user_id) {
                throw ValidationException::withMessages([
                    'owner' => ['Please assign an owner user first.'],
                ]);
            }

            $branchId = $profileData['branch_id'] ?? $onboardingState->initial_branch_id;

            $duplicateCode = SalesMachineProfile::where('tenant_id', $tenant->id)
                ->where('profile_code', $profileData['profile_code'])
                ->where('branch_id', '!=', $branchId)
                ->exists();

            if ($duplicateCode) {
                throw ValidationException::withMessages([
                    'profile_code' => ['Profile code already in use for another branch in this tenant.'],
                ]);
            }

            $payload = [
                'tenant_id' => $tenant->id,
                'branch_id' => $branchId,
                'profile_code' => $profileData['profile_code'],
                'machine_identification_number' => $profileData['machine_identification_number'],
                'machine_serial_number' => $profileData['machine_serial_number'],
                'permit_to_use_number' => $profileData['permit_to_use_number'],
                'authority_to_generate_control_number' => $profileData['authority_to_generate_control_number'],
                'supplier_accreditation_number' => $profileData['supplier_accreditation_number'],
                'permit_issued_at' => $profileData['permit_issued_at'] ?? null,
                'software_license_number' => $profileData['software_license_number'] ?? null,
                'supplier_name' => $profileData['supplier_name'] ?? null,
                'supplier_tin' => $profileData['supplier_tin'] ?? null,
                'supplier_branch_code' => $profileData['supplier_branch_code'] ?? null,
                'supplier_address' => $profileData['supplier_address'] ?? null,
                'supplier_accreditation_issued_at' => $profileData['supplier_accreditation_issued_at'] ?? null,
                'supplier_accreditation_expires_at' => $profileData['supplier_accreditation_expires_at'] ?? null,
                'terminal_identifier' => $profileData['terminal_identifier'] ?? null,
                'offline_sales_enabled' => (bool) ($profileData['offline_sales_enabled'] ?? false),
                'offline_sequence_prefix' => $profileData['offline_sequence_prefix'] ?? null,
                'offline_sequence_next_value' => $profileData['offline_sequence_next_value'] ?? 1,
                'offline_sequence_status' => $profileData['offline_sequence_status'] ?? 'active',
            ];

            $profile = SalesMachineProfile::where('tenant_id', $tenant->id)
                ->where('branch_id', $branchId)
                ->first();

            if ($profile) {
                $profile->update($payload);
            } else {
                $profile = SalesMachineProfile::create($payload);
            }

            $this->recordOnboardingEvent($tenant->id, 'machine_profile_registered', [
                'sales_machine_profile_id' => $profile->id,
                'branch_id' => $profile->branch_id,
                'profile_code' => $profile->profile_code,
                'compliance_ready' => $this->isMachineProfileComplianceComplete($profile),
            ]);

            return $profile;
        });
    }

    public function isMachineProfileComplianceComplete(SalesMachineProfile $profile): bool
    {
        $requiredFields = [
            'machine_identification_number',
            'machine_serial_number',
            'permit_to_use_number',
            'authority_to_generate_control_number',
            'supplier_accreditation_number',
        ];

        foreach ($requiredFields as $field) {
            if (blank($profile->{$field})) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate a unique bootstrap token
     */
    public function generateBootstrapToken(): string
    {
        $token = bin2hex(random_bytes(32));

        // Ensure uniqueness
        while (CompanyOnboardingState::where('bootstrap_token', $token)->exists()) {
            $token = bin2hex(random_bytes(32));
        }

        return $token;
    }

    /**
     * Validate bootstrap token
     */
    public function validateBootstrapToken(string $token): ?CompanyOnboardingState
    {
        $onboardingState = CompanyOnboardingState::byBootstrapToken($token)->first();

        if (!$onboardingState) {
            return null;
        }

        // Check if already used
        if ($onboardingState->status === 'ready' && !$onboardingState->bootstrap_token) {
            return null;
        }

        // Check if expired
        if ($onboardingState->isBootstrapTokenExpired()) {
            return null;
        }

        // Check if locked due to too many attempts
        if ($onboardingState->isBootstrapLocked()) {
            return null;
        }

        return $onboardingState;
    }

    /**
     * Complete bootstrap and set owner password
     */
    public function completeBootstrap(string $token, array $data): User
    {
        return DB::transaction(function () use ($token, $data) {
            // Validate token
            $onboardingState = $this->validateBootstrapToken($token);
            if (!$onboardingState) {
                throw ValidationException::withMessages([
                    'token' => ['Invalid or expired bootstrap token.'],
                ]);
            }

            // Get owner user
            $user = $onboardingState->ownerUser;
            if (!$user) {
                throw ValidationException::withMessages([
                    'user' => ['Owner user not found.'],
                ]);
            }

            // Update user password and activate
            $user->update([
                'password' => Hash::make($data['password']),
                'timezone' => $data['timezone'] ?? 'Asia/Manila',
                'email_verified_at' => now(),
                'status' => 'active',
            ]);

            // Clear bootstrap token
            $onboardingState->update([
                'bootstrap_token' => null,
                'bootstrap_token_expires_at' => null,
                'bootstrap_attempts' => 0,
                'bootstrap_locked_until' => null,
                'status' => 'ready',
                'completed_at' => now(),
            ]);

            // Record events
            $this->recordOnboardingEvent($onboardingState->tenant_id, 'bootstrap_token_used', [
                'user_id' => $user->id,
                'completed_at' => now()->toDateTimeString(),
            ]);

            return $user;
        });
    }

    /**
     * Record bootstrap failure and implement rate limiting
     */
    public function recordBootstrapFailure(string $token): void
    {
        $onboardingState = CompanyOnboardingState::byBootstrapToken($token)->first();
        if (!$onboardingState) {
            return;
        }

        $attempts = $onboardingState->bootstrap_attempts + 1;
        $update = ['bootstrap_attempts' => $attempts];

        // Lock after 5 attempts for 15 minutes
        if ($attempts >= 5) {
            $update['bootstrap_locked_until'] = now()->addMinutes(15);
        }

        $onboardingState->update($update);

        // Record event
        $this->recordOnboardingEvent($onboardingState->tenant_id, 'bootstrap_failed', [
            'user_id' => $onboardingState->owner_user_id,
            'attempt' => $attempts,
            'locked_until' => $update['bootstrap_locked_until'] ?? null,
        ]);
    }

    /**
     * Resend bootstrap link
     */
    public function resendBootstrapLink(Tenant $tenant): CompanyOnboardingState
    {
        return DB::transaction(function () use ($tenant) {
            // Get onboarding state
            $onboardingState = CompanyOnboardingState::where('tenant_id', $tenant->id)->firstOrFail();

            // Only allow resend if owner is assigned
            if (!$onboardingState->owner_user_id) {
                throw ValidationException::withMessages([
                    'owner' => ['Owner user not assigned yet.'],
                ]);
            }

            // Only allow resend if not yet completed
            if ($onboardingState->status === 'ready') {
                throw ValidationException::withMessages([
                    'bootstrap' => ['Onboarding already completed.'],
                ]);
            }

            // Generate new token
            $bootstrapToken = $this->generateBootstrapToken();
            $expiresAt = now()->addDays(7);

            // Update onboarding state
            $onboardingState->update([
                'bootstrap_token' => $bootstrapToken,
                'bootstrap_token_expires_at' => $expiresAt,
                'bootstrap_attempts' => 0,
                'bootstrap_locked_until' => null,
            ]);

            // Record event
            $this->recordOnboardingEvent($tenant->id, 'bootstrap_resent', [
                'user_id' => $onboardingState->owner_user_id,
                'new_expires_at' => $expiresAt->toDateTimeString(),
            ]);

            return $onboardingState;
        });
    }

    /**
     * Record onboarding event for audit trail
     */
    public function recordOnboardingEvent(string $tenantId, string $eventType, array $eventData = []): CompanyOnboardingEvent
    {
        return CompanyOnboardingEvent::create([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'event_data' => $eventData,
            'created_at' => now(),
        ]);
    }

    /**
     * Get onboarding progress for a tenant
     */
    public function getOnboardingProgress(Tenant $tenant): array
    {
        $onboardingState = CompanyOnboardingState::where('tenant_id', $tenant->id)
            ->with(['initialBranch', 'ownerUser', 'events'])
            ->first();

        if (!$onboardingState) {
            $onboardingState = $this->initializeOnboardingState($tenant);
        }

        $machineProfile = null;
        if ($onboardingState->initial_branch_id) {
            $machineProfile = SalesMachineProfile::where('tenant_id', $tenant->id)
                ->where('branch_id', $onboardingState->initial_branch_id)
                ->first();
        }

        $machineProfileReady = $machineProfile ? $this->isMachineProfileComplianceComplete($machineProfile) : false;

        return [
            'state' => $onboardingState,
            'progress_percentage' => $this->resolveProgressPercentage($onboardingState, $machineProfileReady),
            'next_action' => $this->resolveNextAction($onboardingState, $machineProfileReady),
            'status' => $onboardingState->status,
            'is_complete' => $onboardingState->status === 'ready',
            'initial_branch' => $onboardingState->initialBranch,
            'owner_user' => $onboardingState->ownerUser,
            'machine_profile' => $machineProfile,
            'machine_profile_compliance_ready' => $machineProfileReady,
            'bootstrap_token' => $onboardingState->bootstrap_token,
            'can_resend_bootstrap' => !$onboardingState->status === 'ready' && $onboardingState->owner_user_id,
        ];
    }

    private function resolveNextAction(CompanyOnboardingState $state, bool $machineProfileReady): string
    {
        if (!$state->initial_branch_id) {
            return 'Create initial branch';
        }

        if (!$state->owner_user_id) {
            return 'Create owner user';
        }

        if (!$machineProfileReady) {
            return 'Register sales machine profile';
        }

        return $state->status === 'ready' ? 'Onboarding complete' : 'Complete bootstrap';
    }

    private function resolveProgressPercentage(CompanyOnboardingState $state, bool $machineProfileReady): int
    {
        if (!$state->initial_branch_id) {
            return 0;
        }

        if (!$state->owner_user_id) {
            return 33;
        }

        if (!$machineProfileReady) {
            return 66;
        }

        return $state->status === 'ready' ? 100 : 83;
    }

    /**
     * Get onboarding events timeline
     */
    public function getOnboardingTimeline(Tenant $tenant): array
    {
        return CompanyOnboardingEvent::forTenant($tenant->id)
            ->get()
            ->map(function ($event) {
                return [
                    'event_type' => $event->event_type,
                    'event_data' => $event->event_data,
                    'created_at' => $event->created_at,
                    'description' => $this->getEventDescription($event->event_type, $event->event_data),
                ];
            })
            ->toArray();
    }

    /**
     * Get human-readable event description
     */
    private function getEventDescription(string $eventType, array $eventData = []): string
    {
        return match($eventType) {
            'onboarding_initialized' => 'Onboarding initialized',
            'branch_created' => "Initial branch '{$eventData['branch_name']}' created",
            'owner_created' => "Owner user '{$eventData['email']}' created",
            'machine_profile_registered' => "Sales machine profile '{$eventData['profile_code']}' registered",
            'bootstrap_token_generated' => 'Bootstrap token generated',
            'bootstrap_token_used' => 'Onboarding completed by owner',
            'bootstrap_failed' => "Bootstrap attempt failed (attempt {$eventData['attempt']})",
            'bootstrap_resent' => 'Bootstrap link resent',
            default => 'Onboarding event recorded',
        };
    }
}
