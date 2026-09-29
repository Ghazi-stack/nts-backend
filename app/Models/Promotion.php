<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promotion extends Model
{
    use HasFactory;

    public const KIND_AUTOMATIC = 'automatic';
    public const KIND_COUPON    = 'coupon';

    public const EDITOR_STANDARD = 'standard';
    public const EDITOR_LEGACY   = 'legacy';

    public const TYPE_CUSTOM   = 'custom';
    public const TYPE_CART     = 'cart';
    public const TYPE_SHIPPING = 'shipping';

    public const CHANNEL_ANY = 'any';

    public const CONDITION_BUYS_PRODUCTS  = 'buys_products';
    public const CONDITION_ORDER_SUBTOTAL = 'order_subtotal';
    public const CONDITION_NONE           = 'none';

    public const REWARD_GIFT              = 'gift';
    public const REWARD_FREE_SHIPPING     = 'free_shipping';
    public const REWARD_DISCOUNT_SHIPPING = 'discount_shipping';
    public const REWARD_DISCOUNT_PRODUCTS = 'discount_products';
    public const REWARD_DISCOUNT_SUBTOTAL = 'discount_subtotal';
    public const REWARD_FIXED_PRICE       = 'fixed_price';

    public const APPLY_ONCE      = 'once';
    public const APPLY_UNLIMITED = 'unlimited';

    public const BANNER_AVAILABILITY    = 'availability';
    public const BANNER_CONGRATULATIONS = 'congratulations';
    public const BANNER_ELIGIBILITY     = 'eligibility';
    public const BANNER_UPSELL          = 'upsell';

    public const TARGET_CURRENCY             = 'currency';
    public const TARGET_CUSTOMER_GROUP       = 'customer_group';
    public const TARGET_SHIPPING_DESTINATION = 'shipping_destination';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_INACTIVE  = 'inactive';
    public const STATUS_ARCHIVED  = 'archived';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_DISABLED  = 'disabled';

    protected $fillable = [
        'store_id',
        'kind',
        'editor',
        'type',
        'name',
        'display_name',
        'status',
        'priority',
        'currency',
        'channel',
        'is_featured',
        'featured_message',
        'code',
        'coupon',
        'max_redemptions',
        'per_customer_limit',
        'redemptions',
        'can_combine',
        'conditions',
        'actions',
        'starts_at',
        'ends_at',
        'schedule',
        'targeting',
        'banners',
    ];

    protected $casts = [
        'is_featured'        => 'boolean',
        'can_combine'        => 'boolean',
        'coupon'             => 'array',
        'conditions'         => 'array',
        'actions'            => 'array',
        'schedule'           => 'array',
        'targeting'          => 'array',
        'banners'            => 'array',
        'starts_at'          => 'datetime',
        'ends_at'            => 'datetime',
        'max_redemptions'    => 'integer',
        'per_customer_limit' => 'integer',
        'redemptions'        => 'integer',
        'priority'           => 'integer',
    ];

    protected $attributes = [
        'kind'        => self::KIND_AUTOMATIC,
        'editor'      => self::EDITOR_STANDARD,
        'type'        => self::TYPE_CUSTOM,
        'status'      => self::STATUS_DRAFT,
        'channel'     => self::CHANNEL_ANY,
        'is_featured' => false,
        'can_combine' => true,
        'redemptions' => 0,
        'priority'    => 0,
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function couponCodes()
    {
        return $this->hasMany(PromotionCouponCode::class);
    }

    public function appliesToAnyChannel(): bool
    {
        return $this->channel === self::CHANNEL_ANY || empty($this->channel);
    }

    public function hasUsageLimit(): bool
    {
        return !is_null($this->max_redemptions);
    }

    public function remainingRedemptions(): ?int
    {
        if (!$this->hasUsageLimit()) {
            return null;
        }

        return max(0, $this->max_redemptions - (int) $this->redemptions);
    }

    public function isFeaturedEligible(): bool
    {
        return $this->is_featured
            && filled($this->featured_message)
            && filled($this->name);
    }
}
