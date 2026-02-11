<?php

namespace TexHub\Meta\Models;

use Illuminate\Database\Eloquent\Model;

class MetaLog extends Model
{
    protected $fillable = [
        'title',
        'content',
    ];
}
