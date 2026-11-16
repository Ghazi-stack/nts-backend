<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'product_id',
        'entity_id',
        'entity_type',
        'redirect_type',
        'domain',
        'old_url',
        'new_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
