<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VisitSite extends Model
{
     use HasFactory;

    protected $table = 'visits_site'; // ✅ fix table name: use underscore, not dash

    protected $fillable = [
        'visit_date',
        'count',
        'store_id', // ✅ include this so it can be mass-assigned
    ];

    // ✅ Define relationship to Store
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
