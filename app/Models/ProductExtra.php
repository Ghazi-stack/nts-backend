<?php

namespace App\Models;

use App\Models\Store;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductExtra extends Model
{
    use HasFactory;
    protected $table = 'product_extra';

    protected $fillable = [
        'product_id',
        'store_id',
        'retail_price',
        'distributor_name',
        'distributor_sku',
        'event_date_required',
        'gps_gender',
        'gps_age_group',
        'gps_enabled',
        'myob_asset_acct',
        'myob_income_acct',
        'myob_expense_acct',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
