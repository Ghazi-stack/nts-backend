<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Order;
use App\Models\Store;
use App\Models\Customer;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use App\Notifications\CustomResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'userRole',
        'store_name',
        'business_size',
        'region',
        'customer_id',
        'store_id',

        'two_factor_enabled',
        'two_factor_type',
        'two_factor_secret',
        'two_factor_otp',
        'two_factor_otp_expires_at',
        'two_factor_confirmed',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',

        'two_factor_secret',
        'two_factor_otp',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',

        'two_factor_enabled'        => 'boolean',
        'two_factor_confirmed'      => 'boolean',
        'two_factor_otp_expires_at' => 'datetime',
    ];


    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPasswordNotification($token));
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot('store_id')
            ->withTimestamps();
    }

    public function hasPermission($name)
    {
        return $this->permissions()
            ->where('permissions.name', $name)
            ->where('user_permissions.store_id', $this->store_id)
            ->exists();
    }

    public function hasAnyPermission(array $names)
    {
        return $this->permissions()
            ->whereIn('permissions.name', $names)
            ->where('user_permissions.store_id', $this->store_id)
            ->exists();
    }
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class);
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
