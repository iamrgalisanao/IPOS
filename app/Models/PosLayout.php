<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosLayout extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'tenant_id',
        'name',
        'version',
        'schema',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'schema' => 'array',
        'version' => 'integer',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_pos_layout')
            ->withPivot([
                'id',
                'active_from',
                'active_until',
                'is_active',
                'published_by',
                'published_at'
            ])
            ->withTimestamps();
    }
}
