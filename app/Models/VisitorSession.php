<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorSession extends Model
{
    protected $connection = 'tenant';
    protected $fillable = [
        'store_id',
        'session_id',
        'source',
        'label',
        'referrer',
        'host',
    ];
}
