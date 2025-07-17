<?php

namespace App\Models;

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
}
