<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Script extends Model
{

    use HasFactory;
    protected $connection = 'tenant';
    protected $table = 'scripts';

    protected $fillable = [
        'store_id',
        'script_name',
        'description',
        'placement',
        'location',
        'script_category',
        'script_type',
        'script_content'
    ];
}
