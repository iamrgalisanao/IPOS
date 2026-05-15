<?php

namespace App\Services\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class SupportPayloadMasker
{
    public const PROFILE_DEFAULT = 'default';
    public const PROFILE_STRICT = 'strict';

    public const REDACTED = '[REDACTED]';
    public const REDACTED_PAYLOAD = '[REDACTED_PAYLOAD]';

    protected const SENSITIVE_KEYS = [
        'accesstoken',
        'refreshtoken',
        'clientsecret',
        'clientid',
        'apikey',
        'appkey',
        'privatekey',
        'authorization',
        'bearer',
        'token',
        'password',
        'secret',
    ];

    protected const STRING_SECRET_PATTERN = '/authorization\s*:\s*bearer\s+\S+|\bbearer\s+[A-Za-z0-9\-\._~\+\/]+=*|(?:access_token|refresh_token|client_secret|client_id|api[_-]?key|app[_-]?key|private[_-]?key|db[_-]?password|mail[_-]?password|queue[_-]?password|cache[_-]?password|password)\s*[:=]\s*[^\s,;\n\r]+|"(?:access_token|refresh_token|client_secret|client_id|api[_-]?key|app[_-]?key|private[_-]?key|db[_-]?password|mail[_-]?password|queue[_-]?password|cache[_-]?password|password)"\s*:\s*"[^"]+"/i';

    protected const PAYLOAD_KEYS = [
        'rawoauthresponse',
        'providerpayload',
        'providercredentials',
    ];

    protected const DEFAULT_FINANCIAL_FRAGMENTS = [
        'amount',
        'total',
        'subtotal',
        'balance',
        'price',
        'cost',
        'gross',
        'net',
        'tax',
        'revenue',
        'payment',
        'variance',
    ];

    protected const STRICT_IDENTIFIER_KEYS = [
        'id',
        'uuid',
        'tenantid',
        'branchid',
        'supportsessionid',
        'supportuserid',
        'approvedby',
        'realmid',
        'email',
    ];

    public function mask(mixed $payload, string $profile = self::PROFILE_DEFAULT): mixed
    {
        return $this->maskValue($this->normalizePayload($payload), $this->normalizeProfile($profile));
    }

    protected function maskValue(mixed $value, string $profile, ?string $key = null): mixed
    {
        $normalizedKey = $key ? $this->normalizeKey($key) : null;

        if ($normalizedKey && in_array($normalizedKey, self::PAYLOAD_KEYS, true)) {
            return self::REDACTED_PAYLOAD;
        }

        if ($normalizedKey && $this->shouldRedactKey($normalizedKey, $profile)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $masked = [];

            foreach ($value as $childKey => $childValue) {
                $masked[$childKey] = $this->maskValue($this->normalizePayload($childValue), $profile, is_string($childKey) ? $childKey : null);
            }

            return $masked;
        }

        if (is_string($value) && $this->containsSensitiveString($value)) {
            return self::REDACTED;
        }

        return $value;
    }

    protected function normalizePayload(mixed $payload): mixed
    {
        if ($payload instanceof Arrayable) {
            return $payload->toArray();
        }

        if ($payload instanceof JsonSerializable) {
            return $payload->jsonSerialize();
        }

        if (is_object($payload)) {
            return get_object_vars($payload);
        }

        return $payload;
    }

    protected function normalizeProfile(string $profile): string
    {
        return in_array($profile, [self::PROFILE_DEFAULT, self::PROFILE_STRICT], true)
            ? $profile
            : self::PROFILE_DEFAULT;
    }

    protected function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    protected function shouldRedactKey(string $normalizedKey, string $profile): bool
    {
        if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::DEFAULT_FINANCIAL_FRAGMENTS as $fragment) {
            if (str_contains($normalizedKey, $fragment)) {
                return true;
            }
        }

        if ($profile === self::PROFILE_STRICT && in_array($normalizedKey, self::STRICT_IDENTIFIER_KEYS, true)) {
            return true;
        }

        return false;
    }

    protected function containsSensitiveString(string $value): bool
    {
        return (bool) preg_match(self::STRING_SECRET_PATTERN, $value);
    }
}