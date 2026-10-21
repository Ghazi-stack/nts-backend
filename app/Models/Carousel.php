<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Carousel extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = [
        'store_id','alt_text','heading','button_text','text','link','image_url','order'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
    
}
