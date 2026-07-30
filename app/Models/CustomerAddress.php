<?php

namespace App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $fillable = [
        'store_id',
        'customer_id',
        'first_name',
        'last_name',
        'company_name',
        'phone_number',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip',
        'country',
        'is_default',
        'type'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
