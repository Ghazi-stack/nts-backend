<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Currency extends Model
{
    use HasFactory;

    // Aapke Category model jaisa tenant connection
    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'code',
        'name',
        'symbol',
        'symbol_location',
        'decimal_token',
        'thousands_token',
        'decimal_places',
        'exchange_rate',
        'is_default',
        'is_transactional',
        'auto_update',
        'default_for_countries',
        'enabled',
    ];

    protected $casts = [
        'default_for_countries' => 'array',
        'is_default'            => 'boolean',
        'is_transactional'      => 'boolean',
        'auto_update'           => 'boolean',
        'enabled'               => 'boolean',
        'exchange_rate'         => 'decimal:6',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public static function getDefaultForStore($storeId)
    {
        return self::where('store_id', $storeId)
            ->where('is_default', true)
            ->first();
    }
}
