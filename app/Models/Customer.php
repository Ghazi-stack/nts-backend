<?php

namespace App\Models;

use App\Models\User;
use App\Models\Store;
use App\Models\CustomerAddress;
use App\Models\BillingInformation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id',
        'first_name',
        'last_name',
        'name',
        'company_name',
        'email',
        'customer_group',
        'phone',
        'address',
        'store_credit',
        'receive_review_emails',
        'force_password_reset',
        'tax_exempt_code',
        'password',
        'country',
        'state',
        'user_type',
    ];

    protected $casts = [
        'receive_review_emails' => 'boolean',
        'force_password_reset'  => 'boolean',
    ];
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }
    public function billingInformations()
    {
        return $this->hasMany(BillingInformation::class);
    }
    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
