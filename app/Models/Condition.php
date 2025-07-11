<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class Condition extends Model
{
    use HasFactory;
    protected $fillable = ['name'];

    public function products() {
        return $this->hasMany(Product::class);
    }
}
