<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SourceConnection extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'owner_id'];

    protected $hidden = ['credential'];

    protected $casts = [
        'credential' => 'encrypted', 'credential_expires_at' => 'datetime',
        'disconnected_at' => 'datetime', 'retry_at' => 'datetime',
        'query_disclosures' => 'array', 'revision' => 'integer',
    ];

    public function summary(): array
    {
        return $this->only(['id', 'provider', 'external_account_id', 'external_account_login',
            'credential_expires_at', 'disconnected_at', 'query_disclosures', 'revision']);
    }
}
