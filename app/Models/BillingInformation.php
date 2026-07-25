<?php

namespace App\Models;

use App\Models\User;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BillingInformation extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id',
        'customer_id',
        'user_id',
        'first_name',
        'last_name',
        'company_name',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip',
        'country',
        'payment_method',
        'shipping_method',
        'shipping_data',
    ];

    protected $casts = [
        'shipping_data' => 'array',
    ];
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
