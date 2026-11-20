<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionalEmailPhrase extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'template_id',
        'phrase_name',
        'phrase_value',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function template()
    {
        return $this->belongsTo(TransactionalEmailTemplate::class, 'template_id');
    }
}
