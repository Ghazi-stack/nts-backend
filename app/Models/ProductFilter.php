<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductFilter extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = [
        'name',
        'type',
        'is_visible',
        'sort_order',
        'store_id' // ✅ Added 'store_id' to fillable attributes
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
