<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaylistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'title',
        'filepath',
        'duration',
        'sort_order',
        'scheduled_start',
        'scheduled_end',
        'is_active',
    ];

    protected $casts = [
        'duration' => 'float',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Format duration as HH:MM:SS.xx
     */
    public function getFormattedDurationAttribute(): string
    {
        $hours = floor($this->duration / 3600);
        $minutes = floor(($this->duration / 60) % 60);
        $seconds = floor($this->duration % 60);
        $ms = round(($this->duration - floor($this->duration)) * 100);

        return sprintf('%02d:%02d:%02d.%02d', $hours, $minutes, $seconds, $ms);
    }

    /**
     * Format scheduled start as HH:MM:SS
     */
    public function getFormattedStartAttribute(): ?string
    {
        return $this->scheduled_start?->format('H:i:s');
    }

    /**
     * Format scheduled end as HH:MM:SS
     */
    public function getFormattedEndAttribute(): ?string
    {
        return $this->scheduled_end?->format('H:i:s');
    }

    /**
     * Whether this item is a YouTube video (filepath starts with youtube:).
     */
    public function isYouTube(): bool
    {
        return str_starts_with($this->filepath, 'youtube:');
    }

    /**
     * Extract the YouTube video ID from a youtube: prefixed filepath.
     */
    public function getYouTubeIdAttribute(): ?string
    {
        return self::parseYouTubeId($this->filepath);
    }

    /**
     * Parse YouTube video ID from a filepath string.
     */
    public static function parseYouTubeId(string $filepath): ?string
    {
        if (! str_starts_with($filepath, 'youtube:') || strlen($filepath) <= 8) {
            return null;
        }

        return substr($filepath, 8); // strlen('youtube:') === 8
    }
}
