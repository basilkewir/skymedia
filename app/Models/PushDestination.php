<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushDestination extends Model
{
    protected $table = 'push_destinations';

    protected $fillable = [
        'channel_id', 'name', 'protocol', 'url', 'stream_key',
        'username', 'password', 'enabled', 'pid', 'status', 'last_active_at',
    ];

    protected $casts = [
        'enabled'        => 'boolean',
        'pid'            => 'integer',
        'last_active_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function getTargetAttribute(): string
    {
        return rtrim($this->url, '/') . '/' . $this->stream_key;
    }
}
