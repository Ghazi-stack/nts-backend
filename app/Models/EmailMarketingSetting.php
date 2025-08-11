<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmailMarketingSetting extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id',
        'allow_newsletter_subscriptions',
        'check_newsletter_box_by_default',
        'show_newsletter_summary',
        'extra',
    ];

    protected $casts = [
        'allow_newsletter_subscriptions' => 'boolean',
        'check_newsletter_box_by_default' => 'boolean',
        'show_newsletter_summary' => 'boolean',
        'extra' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
