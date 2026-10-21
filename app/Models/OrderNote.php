<?php

namespace App\Models;

use App\Models\User;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderNote extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
     protected $fillable = [
        'order_id',
        'user_id',
        'note',
        'is_admin',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
