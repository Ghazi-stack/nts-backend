<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Newsletter extends Model
{
    use HasFactory,SoftDeletes;
    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'email',
        'name'
    ];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];
}
