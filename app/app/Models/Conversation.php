<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A normalized conversation from any provider.
 *
 * Provider is metadata on the row, not a partition. A query runs across the
 * whole corpus unless the caller filters deliberately, which is the point of
 * bringing several providers into one place: the patterns that only appear
 * across providers are invisible while each history sits in its own silo.
 */
class Conversation extends Model
{
    use HasUuids;

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_SHARED = 'shared';

    protected $fillable = [
        'user_id', 'provider', 'provider_conversation_id', 'title',
        'source_account_label', 'workspace_label',
        'provider_created_at', 'provider_updated_at',
        'first_message_at', 'last_message_at', 'message_count', 'models',
        'visibility', 'content_hash', 'parser_version',
        'first_import_id', 'last_import_id', 'provider_metadata', 'redaction',
    ];

    protected $casts = [
        'provider_created_at' => 'datetime',
        'provider_updated_at' => 'datetime',
        'first_message_at' => 'datetime',
        'last_message_at' => 'datetime',
        'message_count' => 'integer',
        'models' => 'array',
        'provider_metadata' => 'array',
        'redaction' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function rawRecords(): HasMany
    {
        return $this->hasMany(ConversationRawRecord::class);
    }

    /**
     * Best available time for ordering and timeline bucketing.
     *
     * Message timestamps are preferred over the conversation-level ones because
     * providers sometimes omit the latter while stamping every message.
     */
    public function occurredAt(): ?\Illuminate\Support\Carbon
    {
        return $this->first_message_at ?? $this->provider_created_at ?? $this->last_message_at;
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
