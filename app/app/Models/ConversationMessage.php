<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A normalized message.
 *
 * content_text is redacted. The unredacted original lives only in
 * ConversationRawRecord, which no retrieval path reads. Anything that builds a
 * prompt, answers a question, or renders a list works from this model.
 */
class ConversationMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'conversation_id', 'user_id', 'provider', 'provider_message_id',
        'parent_provider_message_id', 'sequence', 'on_active_path',
        'role', 'author_name', 'content_type', 'content_text', 'content_blocks',
        'model_slug', 'provider_created_at', 'char_count', 'attachments',
        'provider_metadata', 'redaction', 'content_hash',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'on_active_path' => 'boolean',
        'content_blocks' => 'array',
        'attachments' => 'array',
        'provider_metadata' => 'array',
        'redaction' => 'array',
        'provider_created_at' => 'datetime',
        'char_count' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
