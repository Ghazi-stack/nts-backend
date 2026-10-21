<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class CentralUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $connection = 'central';

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'two_factor_enabled',
        'two_factor_type',
        'two_factor_secret',
        'two_factor_otp',
        'two_factor_otp_expires_at',
        'two_factor_confirmed',
        'email_verified_at',
        'password',
        'remember_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed' => 'boolean',
            'two_factor_otp_expires_at' => 'datetime',
        ];
    }

    public function tokens()
    {
        $relation = $this->morphMany(
            \App\Models\PersonalAccessToken::class,
            'tokenable'
        );

        $relation->getRelated()->setConnection('central');

        return $relation;
    }

    
    public function permissions()
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permissions',
            'user_id',
            'permission_id'
        )
            ->withPivot('store_id')
            ->withTimestamps();
    }

}