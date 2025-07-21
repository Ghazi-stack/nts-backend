<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;

class ProductFilter extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'type',
        'is_visible',
        'sort_order',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
