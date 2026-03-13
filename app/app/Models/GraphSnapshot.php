<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GraphSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'snapshot_at',
        'payload',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'payload' => 'array',
    ];
}
