<?php

namespace App\Services\POS\OfflineSync;

class OfflineSyncConflictDecision
{
    public function __construct(
        public readonly string $decision,
        public readonly ?string $conflictFamily = null,
        public readonly ?string $reasonCode = null,
        public readonly ?string $reviewSeverity = null,
        public readonly ?string $retryClassification = null,
        public readonly string $cashExposure = 'none',
        public readonly bool $blocksPredecessors = false,
        public readonly bool $blocksSuccessors = false,
        public readonly ?string $suggestedActionCode = null,
        public readonly ?string $diagnosticReference = null,
        public readonly array $metadata = [],
    ) {}

    public static function allowPost(string $cashExposure): self
    {
        return new self(
            decision: 'allow_post',
            cashExposure: $cashExposure,
        );
    }

    public static function retryable(string $reasonCode, string $conflictFamily, string $cashExposure, array $metadata = []): self
    {
        return new self(
            decision: 'retryable_failed',
            conflictFamily: $conflictFamily,
            reasonCode: $reasonCode,
            retryClassification: 'automatic_retry',
            cashExposure: $cashExposure,
            suggestedActionCode: 'retry_after_grace_period',
            metadata: $metadata,
        );
    }

    public static function review(
        string $reasonCode,
        string $conflictFamily,
        string $reviewSeverity,
        string $cashExposure,
        ?string $suggestedActionCode = null,
        bool $blocksSuccessors = false,
        array $metadata = [],
    ): self {
        return new self(
            decision: 'review_required',
            conflictFamily: $conflictFamily,
            reasonCode: $reasonCode,
            reviewSeverity: $reviewSeverity,
            retryClassification: 'support_only',
            cashExposure: $cashExposure,
            blocksSuccessors: $blocksSuccessors,
            suggestedActionCode: $suggestedActionCode,
            metadata: $metadata,
        );
    }

    public static function rejected(string $reasonCode, string $conflictFamily, string $cashExposure, array $metadata = []): self
    {
        return new self(
            decision: 'rejected',
            conflictFamily: $conflictFamily,
            reasonCode: $reasonCode,
            retryClassification: 'non_retryable',
            cashExposure: $cashExposure,
            suggestedActionCode: 'do_not_retry',
            metadata: $metadata,
        );
    }

    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'conflict_family' => $this->conflictFamily,
            'reason_code' => $this->reasonCode,
            'review_severity' => $this->reviewSeverity,
            'retry_classification' => $this->retryClassification,
            'cash_exposure' => $this->cashExposure,
            'blocks_predecessors' => $this->blocksPredecessors,
            'blocks_successors' => $this->blocksSuccessors,
            'suggested_action_code' => $this->suggestedActionCode,
            'diagnostic_reference' => $this->diagnosticReference,
        ];
    }
}
