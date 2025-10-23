<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Logo extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id',
        'logo_type',
        'logo_text',
        'logo_url',
        'favicon_url',
        'logo_size',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
