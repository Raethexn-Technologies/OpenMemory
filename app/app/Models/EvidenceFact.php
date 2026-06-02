<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceFact extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'source_node_id',
        'source_document_id',
        'fact_text',
        'span_start',
        'span_end',
        'observed_at',
        'confidence',
        'metadata',
    ];

    protected $casts = [
        'span_start' => 'integer',
        'span_end' => 'integer',
        'observed_at' => 'datetime',
        'confidence' => 'float',
        'metadata' => 'array',
    ];

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'source_node_id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'source_document_id');
    }
}
