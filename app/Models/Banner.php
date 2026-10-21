<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;
    protected $connection = 'tenant';

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
