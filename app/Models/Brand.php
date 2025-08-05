<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'page_title',
        'meta_keywords',
        'meta_description',
        'search_keywords',
        'brand_url',
        'template_layout',
        'store_id'
    ];
    
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
