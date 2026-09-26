<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContextApplication extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'owner_id'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'capabilities' => 'array', 'grant_revision' => 'integer',
        'source_resources' => 'array',
        'model_disclosure' => 'array',
        'expires_at' => 'datetime', 'revoked_at' => 'datetime',
    ];

    public function summary(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name,
            'capabilities' => $this->capabilities, 'grant_revision' => $this->grant_revision,
            'source_resources' => $this->source_resources ?? [],
            'model_disclosure' => $this->model_disclosure,
            'expires_at' => $this->expires_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
