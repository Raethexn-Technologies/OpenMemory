<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NativeMemory extends Model
{
    protected $guarded = ['id', 'owner_id'];

    protected $casts = ['revision' => 'integer'];

    public function portable(): array
    {
        return [
            'id' => $this->memory_id,
            'content' => $this->content,
            'attribution' => $this->attribution,
            'state' => $this->state,
            'superseded_by' => $this->superseded_by,
            'revision' => $this->revision,
            'created_at' => $this->created_at->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'updated_at' => $this->updated_at->utc()->format('Y-m-d\\TH:i:s\\Z'),
        ];
    }
}
