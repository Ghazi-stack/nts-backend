<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    protected $fillable = [
        'store_id',
        'company',
        'email',
        'full_name',
        'part_number',
        'phone_number',
        'required_qty',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
