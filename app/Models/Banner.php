<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'page_content',
        'location',
        'date_range',
        'visible',
        'placement',
        'store_id',
    ];

    protected $casts = [
        'location' => 'array',
        'visible'  => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
