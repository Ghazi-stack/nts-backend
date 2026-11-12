<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    protected $fillable = [
        'store_id',
        'order_id',
        'customer_id',
        'user_id',
        'severity',
        'type',
        'module',
        'summary',
        'log_data',
        'customer_type',
        'email',
        'logged_at',
    ];

    protected $casts = [
        'log_data'  => 'array',
        'logged_at' => 'datetime',
    ];
}
