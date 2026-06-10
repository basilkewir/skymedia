<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        // Identity
        'name', 'slug', 'notes',

        // Source
        'source_type', 'source_url',

        // Push destination
        'push_protocol', 'push_url', 'push_stream_key',

        // Push video encoding
        'push_video_codec', 'push_video_bitrate', 'push_resolution', 'push_framerate',

        // Push audio encoding
        'push_audio_codec', 'push_audio_bitrate', 'push_audio_samplerate', 'push_audio_channels',

        // DVR
        'dvr_duration', 'segment_duration', 'dvr_path',

        // Runtime state
        'is_active', 'stream_status', 'push_status', 'dvr_status', 'record_status', 'source_live',
        'pid', 'dvr_pid', 'push_pid', 'record_pid', 'retry_count', 'last_error',
        'last_live_at', 'last_check_at',

        // Recording
        'record_duration', 'fallback_recording_path',

        // Behaviour
        'check_interval', 'max_retries',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'source_live'        => 'boolean',
        'dvr_duration'       => 'integer',
        'segment_duration'   => 'integer',
        'check_interval'     => 'integer',
        'max_retries'        => 'integer',
        'pid'                => 'integer',
        'dvr_pid'            => 'integer',
        'push_pid'           => 'integer',
        'retry_count'        => 'integer',
        'push_video_bitrate' => 'integer',
        'push_framerate'     => 'integer',
        'push_audio_bitrate' => 'integer',
        'push_audio_samplerate' => 'integer',
        'push_audio_channels'   => 'integer',
        'last_live_at'          => 'datetime',
        'last_check_at'         => 'datetime',
        'record_duration'       => 'integer',
        'record_pid'            => 'integer',
    ];

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
        return $this->hasMany(Recording::class);
    }

    public function latestCompletedRecording(): ?Recording
    {
        return $this->recordings()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
    }

    public function getPushTargetAttribute(): string
    {
        return rtrim($this->push_url, '/') . '/' . $this->push_stream_key;
    }

    public function getDvrDirectoryAttribute(): string
    {
        return $this->dvr_path
            ?? config('skymedia.dvr_base_path', storage_path('app/dvr')) . '/' . $this->id;
    }

    /** Human-readable DVR window (e.g. "3h 0m" or "30m") */
    public function getDvrWindowLabelAttribute(): string
    {
        $h = intdiv($this->dvr_duration, 3600);
        $m = intdiv($this->dvr_duration % 3600, 60);
        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }

    public function resetRetries(): void
    {
        $this->update(['retry_count' => 0, 'last_error' => null]);
    }

    public function incrementRetry(?string $error = null): bool
    {
        $this->increment('retry_count');
        if ($error) {
            $this->update(['last_error' => $error]);
        }
        return $this->fresh()->retry_count >= $this->max_retries;
    }
}
