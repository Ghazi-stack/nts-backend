<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $fillable = [
        'store_id',
        'product_id',
        'entity_type',
        'old_url',
        'new_url',
        'is_active',
    ];
}
