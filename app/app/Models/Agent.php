<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_user_id', 'graph_user_id', 'name', 'principal',
        'trust_score', 'access_count', 'last_active_at',
    ];

    protected $casts = [
        'trust_score' => 'float',
        'access_count' => 'integer',
        'last_active_at' => 'datetime',
    ];

    public function sharedEdgesAsA(): HasMany
    {
        return $this->hasMany(SharedMemoryEdge::class, 'agent_a_id');
    }

    public function sharedEdgesAsB(): HasMany
    {
        return $this->hasMany(SharedMemoryEdge::class, 'agent_b_id');
    }
}
