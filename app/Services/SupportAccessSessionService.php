<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SupportAccessSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Support\SupportAuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

class SupportAccessSessionService
{
    public function __construct(
        protected SupportContext $supportContext,
        protected SupportAuditLogger $supportAuditLogger
    ) {}

    public function startSession(
        User $supportUser,
        Tenant $tenant,
        string $reason,
        ?Branch $branch = null,
        ?CarbonInterface $expiresAt = null,
        ?User $approvedBy = null,
        string $maskingProfile = 'default',
        array $metadata = []
    ): SupportAccessSession {
        $this->assertSupportUser($supportUser);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Support session reason is required.');
        }

        if ($branch && $branch->tenant_id !== $tenant->id) {
            throw new RuntimeException('Support session branch must belong to the target tenant.');
        }

        $expiresAt = $expiresAt ? Carbon::instance($expiresAt) : now()->addHour();

        if ($expiresAt->lte(now())) {
            throw new InvalidArgumentException('Support session expiry must be in the future.');
        }

        $session = SupportAccessSession::create([
            'support_user_id' => $supportUser->id,
            'tenant_id' => $tenant->id,
            'branch_id' => $branch?->id,
            'reason' => $reason,
            'approved_by' => $approvedBy?->id,
            'started_at' => now(),
            'expires_at' => $expiresAt,
            'status' => SupportAccessSession::STATUS_ACTIVE,
            'masking_profile' => $maskingProfile,
            'metadata' => $metadata,
        ]);

        $session->load(['supportUser', 'tenant', 'branch', 'approvedBy']);
        $this->supportContext->setSession($session);

        $this->supportAuditLogger->log(
            eventType: 'support.session.started',
            supportSession: $session,
            actor: $supportUser,
            metadata: [
                'reason' => $reason,
                'tenant_id' => $tenant->id,
                'branch_id' => $branch?->id,
                'expires_at' => $expiresAt->toISOString(),
                'masking_profile' => $maskingProfile,
            ]
        );

        return $session;
    }

    public function endSession(SupportAccessSession|string $session): SupportAccessSession
    {
        $session = $this->resolveSession($session);

        if ($session->status !== SupportAccessSession::STATUS_ENDED) {
            $session->forceFill([
                'status' => SupportAccessSession::STATUS_ENDED,
                'ended_at' => $session->ended_at ?? now(),
            ])->save();

            $this->supportAuditLogger->log(
                eventType: 'support.session.ended',
                supportSession: $session,
                actor: $session->supportUser,
                metadata: [
                    'tenant_id' => $session->tenant_id,
                    'branch_id' => $session->branch_id,
                    'ended_at' => $session->ended_at?->toISOString(),
                    'masking_profile' => $session->masking_profile,
                ]
            );
        }

        if ($this->supportContext->getSessionId() === $session->id) {
            $this->supportContext->clear();
        }

        return $session->fresh(['supportUser', 'tenant', 'branch', 'approvedBy']);
    }

    public function revokeSession(SupportAccessSession|string $session): SupportAccessSession
    {
        $session = $this->resolveSession($session);

        if ($session->status !== SupportAccessSession::STATUS_REVOKED) {
            $session->forceFill([
                'status' => SupportAccessSession::STATUS_REVOKED,
                'ended_at' => $session->ended_at ?? now(),
            ])->save();

            $this->supportAuditLogger->log(
                eventType: 'support.session.revoked',
                supportSession: $session,
                actor: $session->supportUser,
                status: 'blocked',
                metadata: [
                    'tenant_id' => $session->tenant_id,
                    'branch_id' => $session->branch_id,
                    'ended_at' => $session->ended_at?->toISOString(),
                    'masking_profile' => $session->masking_profile,
                ]
            );
        }

        if ($this->supportContext->getSessionId() === $session->id) {
            $this->supportContext->clear();
        }

        return $session->fresh(['supportUser', 'tenant', 'branch', 'approvedBy']);
    }

    public function resolveActiveSession(SupportAccessSession|string|null $session): ?SupportAccessSession
    {
        if (is_null($session)) {
            return null;
        }

        $session = $this->resolveSession($session, false);

        if (!$session) {
            return null;
        }

        if ($session->status === SupportAccessSession::STATUS_REVOKED || $session->status === SupportAccessSession::STATUS_ENDED) {
            return null;
        }

        if ($session->status === SupportAccessSession::STATUS_EXPIRED || $session->expires_at->lte(now())) {
            if ($session->status !== SupportAccessSession::STATUS_EXPIRED) {
                $session->forceFill([
                    'status' => SupportAccessSession::STATUS_EXPIRED,
                    'ended_at' => $session->ended_at ?? now(),
                ])->save();

                $this->supportAuditLogger->log(
                    eventType: 'support.session.expired',
                    supportSession: $session,
                    actor: $session->supportUser,
                    status: 'blocked',
                    metadata: [
                        'tenant_id' => $session->tenant_id,
                        'branch_id' => $session->branch_id,
                        'expires_at' => $session->expires_at?->toISOString(),
                        'ended_at' => $session->ended_at?->toISOString(),
                        'masking_profile' => $session->masking_profile,
                    ]
                );
            }

            return null;
        }

        $session->load(['supportUser', 'tenant', 'branch', 'approvedBy']);
        $this->supportContext->setSession($session);

        $this->supportAuditLogger->log(
            eventType: 'support.session.accessed',
            supportSession: $session,
            actor: $session->supportUser,
            metadata: [
                'tenant_id' => $session->tenant_id,
                'branch_id' => $session->branch_id,
                'masking_profile' => $session->masking_profile,
            ]
        );

        return $session;
    }

    public function assertActiveSession(SupportAccessSession|string|null $session): SupportAccessSession
    {
        $resolvedSession = $this->resolveActiveSession($session);

        if (!$resolvedSession) {
            throw new RuntimeException('Active support access session not found.');
        }

        return $resolvedSession;
    }

    protected function assertSupportUser(User $supportUser): void
    {
        if (!$supportUser->isPlatformSupport()) {
            throw new RuntimeException('Support access sessions are limited to platform support users.');
        }
    }

    protected function resolveSession(SupportAccessSession|string $session, bool $failIfMissing = true): ?SupportAccessSession
    {
        if ($session instanceof SupportAccessSession) {
            return $session;
        }

        $resolvedSession = SupportAccessSession::query()->find($session);

        if (!$resolvedSession && $failIfMissing) {
            throw new RuntimeException('Support access session not found.');
        }

        return $resolvedSession;
    }
}