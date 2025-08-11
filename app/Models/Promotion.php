<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promotion extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id','type','name','status','priority','currency','channel',
        'code','max_redemptions','per_customer_limit','redemptions',
        'conditions','actions','starts_at','ends_at','extra'
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions'    => 'array',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'extra'      => 'array',
    ];
    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
