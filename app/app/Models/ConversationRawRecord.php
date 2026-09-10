<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preserved provider JSON for one conversation.
 *
 * This is the authoritative record. Normalization, redaction, and any later
 * derivation are all recomputable from it, and none of them may replace it.
 *
 * The payload is unredacted by design, which is why access is narrow: it is read
 * only through an explicit owner-authenticated route and is never joined into
 * retrieval, prompts, or MCP responses. Redaction protects the copies that
 * travel; this copy does not travel.
 */
class ConversationRawRecord extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'import_id', 'user_id', 'provider', 'provider_conversation_id',
        'conversation_id', 'record_type', 'payload', 'payload_bytes', 'payload_sha256',
    ];

    protected $casts = [
        'payload_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ConversationImport::class, 'import_id');
    }
}
