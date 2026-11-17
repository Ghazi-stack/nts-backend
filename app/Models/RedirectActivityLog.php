<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RedirectActivityLog extends Model
{
    protected $connection = 'tenant';

    protected $table = 'redirect_activity_logs';

    protected $fillable = [
        'store_id',
        'type',
        'total_items',
        'successful',
        'errors',
        'status',
        'file_name',
        'download_url',
        's3_path',
        'error_message',
    ];

    protected $casts = [
        'total_items' => 'integer',
        'successful'  => 'integer',
        'errors'      => 'integer',
    ];
}
