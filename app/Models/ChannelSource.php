<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelSource extends Model
{
    protected $fillable = [
        'channel_id',
        'source_url',
        'source_type',
        'priority',
        'is_active',
        'last_error',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'       => 'boolean',
            'priority'        => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
