<?php

namespace ProPhoto\Gallery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use ProPhoto\Access\Models\User;
use ProPhoto\Access\Models\Gallery;

class GalleryShare extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'gallery_id',
        'created_by_user_id',
        'share_token',
        'password',
        'expires_at',
        'max_views',
        'view_count',
        'allow_downloads',
        'allow_comments',
        'settings',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'max_views' => 'integer',
        'view_count' => 'integer',
        'allow_downloads' => 'boolean',
        'allow_comments' => 'boolean',
        'settings' => 'array',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->share_token)) {
                $model->share_token = Str::random(32);
            }
        });
    }

    /**
     * Get the gallery being shared.
     */
    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    /**
     * Get the user who created the share link.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the access logs for this share.
     */
    public function accessLogs()
    {
        return $this->hasMany(GalleryAccessLog::class, 'share_id');
    }

    /**
     * Check if the share link is expired.
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }

        return now()->isAfter($this->expires_at);
    }

    /**
     * Check if the share link has reached max views.
     */
    public function hasReachedMaxViews(): bool
    {
        if (!$this->max_views) {
            return false;
        }

        return $this->view_count >= $this->max_views;
    }

    /**
     * Check if the share link is valid.
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->hasReachedMaxViews();
    }

    /**
     * Increment the view count.
     */
    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    /**
     * Scope to only include active shares.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        })->where(function ($q) {
            $q->whereNull('max_views')
                ->orWhereRaw('view_count < max_views');
        });
    }
}
