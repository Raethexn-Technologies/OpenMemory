<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryEdge extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'from_node_id', 'to_node_id', 'relationship', 'weight', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'weight' => 'float',
        'created_at' => 'datetime',
    ];

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'to_node_id');
    }
}
