<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recording extends Model
{
    protected $fillable = [
        'channel_id', 'filepath', 'filename',
        'duration', 'filesize', 'status',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'duration'     => 'float',
        'filesize'     => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
