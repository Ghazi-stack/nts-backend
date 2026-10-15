<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Store extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'user_id',
        'base_url',
        'store_address',
        'address_type',
        'email',
        'phone',
    ];

    protected $casts = [

        'store_address'  => 'array',
    ];
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
