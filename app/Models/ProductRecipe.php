<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductRecipe extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'ingredient_id',
        'quantity',
        'unit',
        'recipe_line_uuid',
        'recipe_schema_version',
        'recipe_version',
        'is_active',
        'active_slot',
        'supersedes_recipe_id',
        'locked_at',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'recipe_schema_version' => 'integer',
        'recipe_version' => 'integer',
        'is_active' => 'boolean',
        'locked_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $recipe) {
            $recipe->recipe_line_uuid ??= (string) Str::orderedUuid();
            $recipe->recipe_schema_version ??= 1;
            $recipe->recipe_version ??= 1;
            $recipe->is_active ??= true;
            $recipe->active_slot ??= $recipe->is_active ? 'active' : 'inactive:' . ($recipe->id ?? (string) Str::orderedUuid());
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('active_slot', 'active');
    }

    /**
     * The composite product that owns this recipe.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * The ingredient required by this recipe.
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_recipe_id');
    }
}
