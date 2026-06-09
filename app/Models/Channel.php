<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'source_type', 'source_url',
        'push_protocol', 'push_url', 'push_stream_key',
        'dvr_duration', 'segment_duration', 'dvr_path',
        'is_active', 'stream_status', 'source_live',
        'pid', 'dvr_pid', 'last_live_at', 'last_check_at',
        'check_interval', 'max_retries', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'source_live' => 'boolean',
        'dvr_duration' => 'integer',
        'segment_duration' => 'integer',
        'check_interval' => 'integer',
        'max_retries' => 'integer',
        'pid' => 'integer',
        'dvr_pid' => 'integer',
        'last_live_at' => 'datetime',
        'last_check_at' => 'datetime',
    ];

    public function dvrSegments(): HasMany
    {
        return $this->hasMany(DvrSegment::class);
    }

    public function streamLogs(): HasMany
    {
        return $this->hasMany(StreamLog::class);
    }

    public function getPushTargetAttribute(): string
    {
        $url = rtrim($this->push_url, '/');
        $key = $this->push_stream_key;
        return "{$url}/{$key}";
    }

    public function getDvrDirectoryAttribute(): string
    {
        return $this->dvr_path ?? storage_path("app/dvr/{$this->id}");
    }
}
