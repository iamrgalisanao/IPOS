<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuickBooksConnection extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $table = 'quickbooks_connections';

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'tenant_id',
        'realm_id',
        'company_name',
        'status',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'connected_at',
        'disconnected_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED
            && filled($this->realm_id)
            && filled($this->access_token)
            && filled($this->refresh_token)
            && !$this->accessTokenExpired()
            && !$this->refreshTokenExpired();
    }

    public function accessTokenExpired(): bool
    {
        return !$this->access_token_expires_at || $this->access_token_expires_at->isPast();
    }

    public function refreshTokenExpired(): bool
    {
        return !$this->refresh_token_expires_at || $this->refresh_token_expires_at->isPast();
    }

    public function needsReconnect(): bool
    {
        return $this->status !== self::STATUS_CONNECTED || $this->refreshTokenExpired();
    }
}
