<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Script extends Model
{

    use HasFactory;
    protected $table = 'scripts';

    protected $fillable = [
        'script_name',
        'description',
        'placement',
        'location',
        'script_category',
        'script_type',
        'script_content'
    ];
}
