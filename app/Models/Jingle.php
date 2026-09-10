<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Jingle extends Model
{
    protected $fillable = ['name', 'filepath', 'mime_type', 'filesize', 'duration', 'sort_order'];
    protected $casts = ['filesize' => 'integer', 'sort_order' => 'integer', 'duration' => 'float'];
}
