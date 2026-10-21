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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            CentralUser::class,
            'user_permissions',
            'permission_id',
            'user_id'
        )
            ->withPivot('store_id')
            ->withTimestamps();
    }
}
