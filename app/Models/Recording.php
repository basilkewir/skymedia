<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recording extends Model
{
    protected $fillable = [
        'channel_id', 'filepath', 'filesize', 'duration',
        'status', 'started_at', 'completed_at', 'error',
    ];

    protected $casts = [
        'filesize'     => 'integer',
        'duration'     => 'float',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isRecording(): bool { return $this->status === 'recording'; }
    public function isFailed(): bool    { return $this->status === 'failed'; }
}
