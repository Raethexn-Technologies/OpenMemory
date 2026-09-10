<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One archive import run.
 *
 * The outermost link of the provenance chain: a derived claim traces to a
 * message, the message to a conversation, and the conversation to the import
 * that read it out of a specific file with a specific SHA-256.
 */
class ConversationImport extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'provider', 'adapter_version', 'source_label', 'source_path',
        'source_bytes', 'source_sha256', 'status', 'started_at', 'finished_at',
        'stats', 'warnings', 'error',
    ];

    protected $casts = [
        'source_bytes' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'stats' => 'array',
        'warnings' => 'array',
    ];

    public function rawRecords(): HasMany
    {
        return $this->hasMany(ConversationRawRecord::class, 'import_id');
    }
}
