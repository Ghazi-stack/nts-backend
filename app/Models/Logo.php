<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Logo extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = [
        'store_id',
        'logo_text',
        'logo_type',
        'logo_url',
        'favicon_url',
        'logo_size',
        'dimensions',
    ];

    protected $casts = [
        'dimensions' => 'array', // width & height object
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
