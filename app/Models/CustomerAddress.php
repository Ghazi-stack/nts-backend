<?php

namespace App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $fillable = [
        'customer_id',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip',
        'country',
        'is_default'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
