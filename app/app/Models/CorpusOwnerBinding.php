<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorpusOwnerBinding extends Model
{
    protected $fillable = ['user_id', 'owner_key'];
}
