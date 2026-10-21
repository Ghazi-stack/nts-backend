<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmailMarketingSetting extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = [
        'store_id',
        'allowNewsletterSubscriptions',
        'checkNewsletterBoxByDefault',
        'newsletterSummaryText',
        'showNewsletterSummary',
    ];

    protected $casts = [
        'allowNewsletterSubscriptions' => 'boolean',
        'checkNewsletterBoxByDefault' => 'boolean',
        'newsletterSummaryText' => 'string',
        'showNewsletterSummary' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
