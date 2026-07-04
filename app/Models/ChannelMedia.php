<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelMedia extends Model
{
    protected $table = 'channel_media';
    protected $fillable = ['channel_id', 'type', 'name', 'filepath', 'mime_type', 'filesize', 'sort_order', 'is_active'];
    protected $casts = ['filesize' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    public function channel() { return $this->belongsTo(Channel::class); }
}
