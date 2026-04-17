<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FedexQuoteLog extends Model
{
    use HasFactory;

    protected $table = 'fedex_quote_logs';

    protected $fillable = [
        'fedex_configuration_id',
        'origin_postal_code',
        'origin_country_code',
        'destination_postal_code',
        'destination_country_code',
        'destination_state',
        'destination_city',
        'total_weight',
        'weight_unit',
        'package_length',
        'package_width',
        'package_height',
        'dimension_unit',
        'package_value',
        'rate_response',
        'status',
        'error_message',
    ];

    protected $casts = [
        'rate_response' => 'array',
        'total_weight'  => 'decimal:2',
        'package_length' => 'decimal:2',
        'package_width' => 'decimal:2',
        'package_height' => 'decimal:2',
        'package_value' => 'decimal:2',
    ];

    // ───────────────────────────────────────
    // Relationships
    // ───────────────────────────────────────

    public function fedexConfiguration()
    {
        return $this->belongsTo(FedexConfiguration::class, 'fedex_configuration_id');
    }
}
