<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DvrSegment extends Model
{
    protected $fillable = [
        'channel_id', 'filename', 'filepath', 'duration',
        'sequence', 'filesize', 'recorded_at', 'is_available',
    ];

    protected $casts = [
        'duration' => 'float',
        'sequence' => 'integer',
        'filesize' => 'integer',
        'recorded_at' => 'datetime',
        'is_available' => 'boolean',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
