<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffLog extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'username',
        'action',
        'ip_address',
        'action_type',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Easy helper to create log
     */
    public static function record(array $data): self
    {
        $user = auth('sanctum')->user();

        return self::create([
            'store_id'    => $data['store_id'] ?? current_store_id(),
            'user_id'     => $data['user_id'] ?? ($user?->id),
            'username'    => $data['username'] ?? ($user?->email),
            'action'      => $data['action'],
            'ip_address'  => $data['ip_address'] ?? request()->ip(),
            'action_type' => $data['action_type'] ?? null,
            'model_type'  => $data['model_type'] ?? null,
            'model_id'    => $data['model_id'] ?? null,
            'old_values'  => $data['old_values'] ?? null,
            'new_values'  => $data['new_values'] ?? null,
        ]);
    }
}
