<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCartContext extends Model
{
    use HasFactory;

    protected $table = 'user_cart_contexts';

    protected $fillable = [
        'store_id',
        'user_id',
        'session_id',
        'quote_token',
    ];
}