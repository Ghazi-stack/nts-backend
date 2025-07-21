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
        'base_url',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
