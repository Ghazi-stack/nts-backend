<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnSetting extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'returns_enabled',
        'return_instructions',
        'return_reasons',
        'return_actions',
        'use_default_reasons_actions',
        'allow_store_credits',
        'notify_new_return',
        'notify_customer_confirmation',
        'notify_status_change',
    ];

    protected $casts = [
        'returns_enabled'               => 'boolean',
        'use_default_reasons_actions'   => 'boolean',
        'allow_store_credits'           => 'boolean',
        'notify_new_return'             => 'boolean',
        'notify_customer_confirmation'  => 'boolean',
        'notify_status_change'          => 'boolean',
        'return_reasons'                => 'array',
        'return_actions'                => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
