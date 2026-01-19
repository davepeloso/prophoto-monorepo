<?php

namespace ProPhoto\Gallery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ProPhoto\Interactions\Models\ImageInteraction;

class Image extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'gallery_id',
        'filename',
        'imagekit_file_id',
        'imagekit_url',
        'imagekit_thumbnail_url',
        'file_size',
        'mime_type',
        'width',
        'height',
        'metadata',
        'sort_order',
        'uploaded_at',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'sort_order' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    /**
     * Get the gallery that owns this image.
     */
    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    /**
     * Get the user who uploaded this image.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'uploaded_by_user_id');
    }

    /**
     * Get the versions of this image.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ImageVersion::class);
    }

    /**
     * Get the interactions for this image.
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(ImageInteraction::class);
    }

    /**
     * Get the latest version of this image.
     */
    public function latestVersion()
    {
        return $this->versions()->latest('version_number')->first();
    }

    /**
     * Get the average rating for this image.
     */
    public function getAverageRatingAttribute(): ?float
    {
        return $this->interactions()
            ->whereNotNull('rating')
            ->avg('rating');
    }

    /**
     * Check if this image is approved for marketing.
     */
    public function getIsApprovedAttribute(): bool
    {
        return $this->interactions()
            ->where('approved_for_marketing', true)
            ->exists();
    }

    /**
     * Check if this image has edit requests.
     */
    public function getHasEditRequestAttribute(): bool
    {
        return $this->interactions()
            ->where('edit_requested', true)
            ->exists();
    }

    /**
     * Get human-readable file size.
     */
    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    /**
     * Get EXIF metadata value.
     */
    public function getExif(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }
}
