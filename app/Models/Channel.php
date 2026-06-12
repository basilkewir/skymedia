<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'notes',
        'source_type', 'source_url',
        'push_protocol', 'push_url', 'push_stream_key', 'push_username', 'push_password',
        // Video encoding
        'push_video_codec', 'push_video_bitrate', 'push_resolution', 'push_framerate',
        // Audio encoding
        'push_audio_codec', 'push_audio_bitrate', 'push_audio_samplerate', 'push_audio_channels',
        // DVR
        'dvr_duration', 'segment_duration', 'dvr_path',
        // Recording / fallback
        'record_duration', 'fallback_recording_path',
        // Runtime state
        'is_active', 'stream_status', 'playout_status', 'push_status', 'dvr_status', 'record_status', 'source_live',
        'pid', 'playout_pid', 'push_pid', 'record_pid',
        'retry_count', 'last_error',
        'last_live_at', 'last_check_at',
        'check_interval', 'max_retries',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'source_live'            => 'boolean',
        'dvr_duration'           => 'integer',
        'segment_duration'       => 'integer',
        'record_duration'        => 'integer',
        'check_interval'         => 'integer',
        'max_retries'            => 'integer',
        'retry_count'            => 'integer',
        'pid'                    => 'integer',
        'playout_pid'            => 'integer',
        'push_pid'               => 'integer',
        'record_pid'             => 'integer',
        'push_video_bitrate'     => 'integer',
        'push_framerate'         => 'integer',
        'push_audio_bitrate'     => 'integer',
        'push_audio_samplerate'  => 'integer',
        'push_audio_channels'    => 'integer',
        'last_live_at'           => 'datetime',
        'last_check_at'          => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

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

    // ── Computed attributes ───────────────────────────────────────────────────

    public function getPushTargetAttribute(): string
    {
        return rtrim($this->push_url, '/') . '/' . $this->push_stream_key;
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
        if (!$this->record_duration) return 'Disabled';
        $h = intdiv($this->record_duration, 3600);
        $m = intdiv($this->record_duration % 3600, 60);
        return $h > 0 ? "{$h}h {$m}m per file" : "{$m}m per file";
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
