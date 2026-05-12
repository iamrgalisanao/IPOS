<?php

namespace App\Services\Support;

use App\Models\SupportAccessSession;
use App\Models\SupportAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

class SupportAuditLogger
{
    public function __construct(
        protected SupportPayloadMasker $payloadMasker
    ) {}

    public function log(
        string $eventType,
        ?SupportAccessSession $supportSession = null,
        ?User $actor = null,
        ?string $status = 'allowed',
        ?string $routeName = null,
        ?string $path = null,
        ?string $method = null,
        array $metadata = []
    ): SupportAuditEvent {
        return SupportAuditEvent::create([
            'event_type' => $eventType,
            'support_session_id' => $supportSession?->id,
            'actor_id' => $actor?->id ?? Auth::id(),
            'route_name' => $routeName ?? Request::route()?->getName(),
            'path' => $path ?? Request::path(),
            'method' => $method ?? Request::method(),
            'status' => $status ?? 'allowed',
            'metadata' => $this->maskMetadata($metadata),
        ]);
    }

    protected function maskMetadata(array $metadata): ?array
    {
        if ($metadata === []) {
            return null;
        }

        try {
            $masked = $this->payloadMasker->mask($metadata, $metadata['masking_profile'] ?? SupportPayloadMasker::PROFILE_DEFAULT);

            return is_array($masked)
                ? $masked
                : ['payload' => SupportPayloadMasker::REDACTED_PAYLOAD];
        } catch (Throwable $throwable) {
            return [
                'payload' => SupportPayloadMasker::REDACTED_PAYLOAD,
                'masking_error' => class_basename($throwable),
            ];
        }
    }
}