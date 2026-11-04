<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommentSetting extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'comment_system',
        'enable_product_reviews',
        'only_accept_from_past_customers',
    ];

    protected $casts = [
        'enable_product_reviews'            => 'boolean',
        'only_accept_from_past_customers'   => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
