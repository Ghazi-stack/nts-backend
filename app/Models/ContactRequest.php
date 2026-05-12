<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    protected $fillable = [
        'store_id',
        'full_name',
        'phone_number',
        'email',
        'order_number',
        'company_name',
        'rma_number',
        'comments',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
