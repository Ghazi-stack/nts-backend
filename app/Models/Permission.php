<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'group',
        'name',
        'label',
        'slug',
        'sort_order',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_permissions')
            ->withPivot('store_id')
            ->withTimestamps();
    }
}
