<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;

class BulkInquiry extends Model
{
      protected $fillable = [
        'store_id',
        'email',
        'full_name',
        'phone',
        'comments',
        'quantity',
        'sku',
    ];
     public function store()
     {
        return $this->belongsTo(Store::class);
     }
}
