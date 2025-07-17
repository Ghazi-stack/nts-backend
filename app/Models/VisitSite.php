<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitSite extends Model
{
    use HasFactory;
    protected $table = 'visits-site'; 
    protected $fillable = ['visit_date', 'count'];
}
