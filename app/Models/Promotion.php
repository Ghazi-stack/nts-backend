<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'kind',
        'type',
        'name',
        'display_name',
        'status',
        'priority',
        'currency',
        'channel',
        'is_featured',
        'featured_message',
        'max_redemptions',
        'redemptions',
        'can_combine',
        'conditions',
        'actions',
        'starts_at',
        'ends_at',
        'schedule',
        'targeting',
        'banners',
    ];

    protected $casts = [
        'is_featured'     => 'boolean',
        'can_combine'     => 'boolean',
        'conditions'      => 'array',
        'actions'         => 'array',
        'schedule'        => 'array',
        'targeting'       => 'array',
        'banners'         => 'array',
        'starts_at'       => 'datetime',
        'ends_at'         => 'datetime',
        'max_redemptions' => 'integer',
        'redemptions'     => 'integer',
        'priority'        => 'integer',
    ];

    protected $attributes = [
        'kind'        => 'automatic',
        'type'        => 'custom',
        'status'      => 'draft',
        'channel'     => 'any',
        'is_featured' => false,
        'can_combine' => true,
        'redemptions' => 0,
        'priority'    => 0,
    ];

    public function storeRelation()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
