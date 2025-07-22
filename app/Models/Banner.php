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
        'image',
        'link',
        'status',
        'store_id'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}