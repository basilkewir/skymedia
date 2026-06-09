<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StreamLog extends Model
{
    protected $fillable = [
        'channel_id', 'level', 'event', 'message', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
