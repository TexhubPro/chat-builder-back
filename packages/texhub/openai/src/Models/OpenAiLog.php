<?php

namespace TexHub\OpenAi\Models;

use Illuminate\Database\Eloquent\Model;

class OpenAiLog extends Model
{
    protected $table = 'openai_logs';

    protected $fillable = [
        'title',
        'content',
    ];
}
