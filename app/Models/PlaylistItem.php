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
        'custom_title',
        'media_group',
        'media_type',
        'filepath',
        'duration',
        'sort_order',
        'scheduled_start',
        'scheduled_end',
        'is_active',
    ];

    /**
     * Groups and their overlay suppression rules:
     *   default  — all overlays shown (logo, ticker, clock, lowerthird/title)
     *   jingle   — ALL overlays suppressed (clean air break)
     *   ads      — logo + ticker + clock shown, but NOW PLAYING title hidden
     *   clean    — ALL overlays suppressed (alias for jingle, legacy)
     */
    public const CLEAN_GROUPS = ['clean', 'jingle'];
    public const NO_TITLE_GROUPS = ['clean', 'jingle', 'ads'];

    /** Returns false when ALL overlays should be suppressed for this item. */
    public function hasOverlays(): bool
    {
        return ! in_array($this->media_group ?? 'default', self::CLEAN_GROUPS, true);
    }

    /** Returns false when the NOW PLAYING / movie title should be hidden. */
    public function hasTitle(): bool
    {
        return ! in_array($this->media_group ?? 'default', self::NO_TITLE_GROUPS, true);
    }

    public function isJingle(): bool
    {
        return in_array($this->media_group ?? 'default', self::CLEAN_GROUPS, true);
    }

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
     * Get the display title — custom_title if set, otherwise the original title.
     */
    public function getDisplayTitleAttribute(): string
    {
        return $this->custom_title !== null && $this->custom_title !== ''
            ? $this->custom_title
            : $this->title;
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
