<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RedactionPolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'preset',
        'rules',
    ];

    protected $casts = [
        'rules' => 'array',
    ];
}
