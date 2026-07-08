<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Channel extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'notes',
        'user_id',
        'source_type', 'ingest_mode', 'source_url', 'youtube_cookies', 'ingest_port', 'rtmp_input_key', 'relay_pid',
        'current_source_id',
        'push_protocol', 'push_url', 'push_stream_key', 'push_username', 'push_password',
        'push_hls_segment_duration', 'push_hls_list_size',
        // Video encoding
        'push_video_codec', 'push_video_bitrate', 'push_resolution', 'push_framerate',
        // Audio encoding
        'push_audio_codec', 'push_audio_bitrate', 'push_audio_samplerate', 'push_audio_channels',
        // DVR
        'dvr_duration', 'segment_duration', 'dvr_enabled', 'dvr_path',
        'logo_media_id', 'logo_position', 'ticker_enabled', 'ticker_text',
        // Storage quota
        'storage_quota_bytes', 'storage_used_bytes',
        // Locale
        'timezone', 'locale',
        // Recording / fallback
        'record_duration', 'keep_recordings', 'recording_burn_timestamp', 'fallback_recording_path', 'fallback_vod_name',
        // Schedule
        'schedule_start', 'schedule_stop', 'schedule_days',
        // Runtime state
        'is_active', 'stream_status', 'playout_status', 'push_status', 'dvr_status', 'record_status', 'source_live',
        'pid', 'playout_pid', 'push_pid', 'record_pid', 'relay_pid',
        'retry_count', 'last_error',
        'last_live_at', 'last_check_at',
        'check_interval', 'max_retries',
    ];

    protected $attributes = [
        // No default quota — null means unlimited
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'source_live' => 'boolean',
        'dvr_duration' => 'integer',
        'segment_duration' => 'integer',
        'dvr_enabled' => 'boolean',
        'ticker_enabled' => 'boolean',
        'record_duration' => 'integer',
        'keep_recordings' => 'integer',
        'recording_burn_timestamp' => 'boolean',
        'check_interval' => 'integer',
        'max_retries' => 'integer',
        'retry_count' => 'integer',
        'pid' => 'integer',
        'playout_pid' => 'integer',
        'push_pid' => 'integer',
        'record_pid' => 'integer',
        'relay_pid' => 'integer',
        'ingest_port' => 'integer',
        'push_video_bitrate' => 'integer',
        'push_framerate' => 'integer',
        'push_hls_segment_duration' => 'integer',
        'push_hls_list_size' => 'integer',
        'push_audio_bitrate' => 'integer',
        'push_audio_samplerate' => 'integer',
        'push_audio_channels' => 'integer',
        'storage_quota_bytes' => 'integer',
        'storage_used_bytes' => 'integer',
        'last_live_at' => 'datetime',
        'last_check_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dvrSegments(): HasMany
    {
        return $this->hasMany(DvrSegment::class);
    }

    public function streamLogs(): HasMany
    {
        return $this->hasMany(StreamLog::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(Recording::class)->latest();
    }

    public function pushDestinations(): HasMany
    {
        return $this->hasMany(PushDestination::class);
    }

    public function media(): HasMany { return $this->hasMany(ChannelMedia::class)->orderBy('sort_order'); }
    public function logoMedia() { return $this->belongsTo(ChannelMedia::class, 'logo_media_id'); }

    public function channelSources(): HasMany
    {
        return $this->hasMany(ChannelSource::class)->orderBy('priority');
    }

    public function currentSource()
    {
        return $this->belongsTo(ChannelSource::class, 'current_source_id');
    }

    // ── Computed attributes ───────────────────────────────────────────────────

    public function getPushTargetAttribute(): string
    {
        return rtrim($this->push_url, '/') . '/' . $this->push_stream_key;
    }

    public function isYoutube(): bool
    {
        return $this->source_type === 'youtube';
    }

    public function isPushIngest(): bool
    {
        return $this->ingest_mode === 'push' && in_array($this->source_type, ['rtmp', 'srt'], true);
    }

    /**
     * Whether this channel has multiple sources configured.
     */
    public function hasMultipleSources(): bool
    {
        return $this->channelSources()->count() > 1;
    }

    /**
     * Get the effective source URL to use for ingest.
     * If a current_source_id is set, use that source's URL.
     * Otherwise fall back to the legacy source_url field.
     */
    public function effectiveSourceUrl(): string
    {
        if ($this->currentSource) {
            return $this->currentSource->source_url;
        }
        return $this->source_url;
    }

    /**
     * Get the effective source type for the active source.
     */
    public function effectiveSourceType(): string
    {
        if ($this->currentSource) {
            return $this->currentSource->source_type;
        }
        return $this->source_type;
    }

    /**
     * Get the next source to try after the current one failed.
     * Returns the next ChannelSource by priority, or null if none left.
     */
    public function nextSource(): ?ChannelSource
    {
        $currentId = $this->current_source_id;
        $query = $this->channelSources()->where('is_active', true);

        if ($currentId) {
            $currentPriority = $this->currentSource?->priority ?? 0;
            $query->where('priority', '>', $currentPriority);
        }

        return $query->orderBy('priority')->first();
    }

    /**
     * Activate a specific source as the current one and clear the legacy source_url.
     */
    public function activateSource(ChannelSource $source): void
    {
        $this->update([
            'current_source_id' => $source->id,
            'source_url'        => $source->source_url,
            'source_type'       => $source->source_type,
        ]);
    }

    public function getIngestListenUrlAttribute(): ?string
    {
        if (! $this->isPushIngest() || ! $this->ingest_port) {
            return null;
        }

        if ($this->source_type === 'srt') {
            return "srt://0.0.0.0:{$this->ingest_port}?mode=listener";
        }

        return "rtmp://0.0.0.0:{$this->ingest_port}/live/{$this->rtmp_input_key}";
    }

    public function getPublishedIngestUrlAttribute(): ?string
    {
        $host = (string) config('skymedia.server_ip', request()->getHost());
        if (! $this->isPushIngest() || ! $this->ingest_port) return null;

        return $this->source_type === 'srt'
            ? "srt://{$host}:{$this->ingest_port}"
            : "rtmp://{$host}:{$this->ingest_port}/live/{$this->rtmp_input_key}";
    }

    public function getPublishedIngestServerAttribute(): ?string
    {
        $host = (string) config('skymedia.server_ip', request()->getHost());
        if (! $this->isPushIngest() || ! $this->ingest_port) return null;

        return $this->source_type === 'srt'
            ? "srt://{$host}:{$this->ingest_port}"
            : "rtmp://{$host}:{$this->ingest_port}/live";
    }

    public function getDvrDirectoryAttribute(): string
    {
        return $this->dvr_path
            ?? config('skymedia.dvr_base_path', storage_path('app/dvr')) . '/' . $this->id;
    }

    public function getDvrWindowLabelAttribute(): string
    {
        $h = intdiv($this->dvr_duration, 3600);
        $m = intdiv($this->dvr_duration % 3600, 60);

        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }

    public function getRecordDurationLabelAttribute(): string
    {
        if (! $this->record_duration) {
            return 'Disabled';
        }
        $h = intdiv($this->record_duration, 3600);
        $m = intdiv($this->record_duration % 3600, 60);

        return $h > 0 ? "{$h}h {$m}m per file" : "{$m}m per file";
    }

    public function getStorageQuotaLabelAttribute(): string
    {
        if (! $this->storage_quota_bytes) {
            return 'Unlimited';
        }
        $bytes = $this->storage_quota_bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return sprintf('%.2f %s', $bytes, $units[$i]);
    }

    public function getStorageUsedLabelAttribute(): string
    {
        $bytes = $this->storage_used_bytes ?? 0;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return sprintf('%.2f %s', $bytes, $units[$i]);
    }

    public function getStorageUsagePercentAttribute(): ?float
    {
        if (! $this->storage_quota_bytes || $this->storage_quota_bytes <= 0) {
            return null;
        }

        return round(($this->storage_used_bytes / $this->storage_quota_bytes) * 100, 2);
    }

    public function hasStorageQuota(): bool
    {
        return $this->storage_quota_bytes > 0;
    }

    public function canStore(int $bytes): bool
    {
        if (! $this->hasStorageQuota()) {
            return true;
        }

        return ($this->storage_used_bytes + $bytes) <= $this->storage_quota_bytes;
    }

    public function incrementStorageUsed(int $bytes): void
    {
        $this->increment('storage_used_bytes', $bytes);
    }

    public function decrementStorageUsed(int $bytes): void
    {
        $this->decrement('storage_used_bytes', $bytes);
    }

    // ── Retry helpers ─────────────────────────────────────────────────────────

    public function resetRetries(): void
    {
        if ($this->retry_count > 0 || $this->last_error) {
            $this->update(['retry_count' => 0, 'last_error' => null]);
        }
    }

    /**
     * @return bool true when max_retries has been reached
     */
    public function incrementRetry(?string $error = null): bool
    {
        $this->increment('retry_count');
        if ($error) {
            $this->update(['last_error' => substr($error, 0, 500)]);
        }

        return $this->fresh()->retry_count >= $this->max_retries;
    }
}
