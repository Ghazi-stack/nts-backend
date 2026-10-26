<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Store extends Model
{
    use HasFactory;
    protected $connection = 'central';
    protected $fillable = [
        'name',
        'store_hash',
        'tenant_database',
        'provisioning_status',
        'user_id',
        'base_url',
        'store_address',
        'store_address_full',
        'address_type',
        'email',
        'phone',
    ];

    protected $casts = [

        'store_address'  => 'array',
    ];

    protected $hidden = [
        'db_username',
        'db_password_encrypted',
        'db_host',
        'db_port',
        'tenant_database',
        'provisioning_status'
    ];
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
