<?php

namespace App\Models;

use App\Models\Store;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Cart extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'session_id',
        'product_id',
        'quantity',
        'quote_token',
        'isdraftcart',
        'email_1hr_sent_at',
        'email_24hr_sent_at',
        'email_48hr_sent_at',
    ];

    protected $casts = [
        'isdraftcart' => 'boolean',
        'email_1hr_sent_at'  => 'datetime',
        'email_24hr_sent_at' => 'datetime',
        'email_48hr_sent_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
