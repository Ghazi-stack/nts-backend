<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderNotificationSetting extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'email_address',
        'email_enabled',
        'notify_on_statuses',
    ];

    protected $casts = [
        'email_enabled'      => 'boolean',
        'notify_on_statuses' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
