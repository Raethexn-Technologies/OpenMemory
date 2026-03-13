<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedMemoryEdge extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'owner_user_id', 'agent_a_id', 'agent_b_id',
        'node_a_id', 'node_b_id', 'content_hash',
        'weight', 'access_count', 'last_accessed_at',
    ];

    protected $casts = [
        'weight' => 'float',
        'access_count' => 'integer',
        'last_accessed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function agentA(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_a_id');
    }

    public function agentB(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_b_id');
    }

    public function nodeA(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'node_a_id');
    }

    public function nodeB(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'node_b_id');
    }
}
