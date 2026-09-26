<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceResource extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['selected' => 'boolean', 'revision' => 'integer'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }
}
