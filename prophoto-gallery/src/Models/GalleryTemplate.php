<?php

namespace ProPhoto\Gallery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ProPhoto\Access\Models\User;

class GalleryTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'user_id',
        'is_global',
        'settings',
        'metadata',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'settings' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Get the user who created the template.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to only include global templates.
     */
    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    /**
     * Scope to only include user-specific templates.
     */
    public function scopeUserOnly($query)
    {
        return $query->where('is_global', false);
    }

    /**
     * Scope to templates accessible by a specific user.
     */
    public function scopeAccessibleBy($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('is_global', true)
                ->orWhere('user_id', $userId);
        });
    }

    /**
     * Apply this template's settings to a gallery.
     */
    public function applyToGallery($gallery): void
    {
        if ($this->settings && is_array($this->settings)) {
            $gallery->update([
                'settings' => array_merge($gallery->settings ?? [], $this->settings)
            ]);
        }
    }
}
