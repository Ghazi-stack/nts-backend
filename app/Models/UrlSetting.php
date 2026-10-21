<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UrlSetting extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $fillable = [
        'store_id',
        'entity_type',
        'format_type',
        'custom_format',
    ];

    /**
     * Store relation (agar aapka Store model hai)
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
