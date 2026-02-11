<?php

namespace TexHub\AlifBank\Models;

use Illuminate\Database\Eloquent\Model;

class AlifBankLog extends Model
{
    protected $table = 'alifbank_logs';

    protected $fillable = [
        'title',
        'content',
    ];
}
