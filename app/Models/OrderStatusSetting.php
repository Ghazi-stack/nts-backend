<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderStatusSetting extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'status_key',
        'custom_name',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
