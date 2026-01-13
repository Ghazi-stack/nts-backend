<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    protected $table = 'store_settings';

    protected $fillable = [
        'store_id',
        'website',
        'display',
        'dateTimezone',
        'miscellaneous',
        'search',
        'securityPrivacy',
        'urlStructure',
    ];

    protected $casts = [
        'website'          => 'array',
        'display'          => 'array',
        'dateTimezone'     => 'array',
        'miscellaneous'    => 'array',
        'search'           => 'array',
        'securityPrivacy'  => 'array',
        'urlStructure'     => 'array',
    ];
}
