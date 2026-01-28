<?php

namespace App\Http\Resources;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $price = $this->price ?? 0;
        $retailPrice = $this->extra->retail_price ?? 0;

        // Calculate saving (MSRP)
        $msrp = ($retailPrice > $price) ? round($retailPrice - $price, 2) : 0;

        // Percentage saving
        $savingPercent = ($retailPrice > 0 && $msrp > 0)
            ? round(($msrp / $retailPrice) * 100, 2)
            : 0;

             $dimensions = [];
        if (is_array($this->dimensions)) {
            $dimensions = $this->dimensions;
        } elseif ($this->dimensions) {
            $decoded = json_decode($this->dimensions, true);
            if (is_array($decoded)) {
                $dimensions = $decoded;
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'price' => $price,
            'retailPrice' => $retailPrice,
            'msrp' => $msrp,
            'bestSelling' => $this->sold_count,
            'condition' => $this->condition,
            'costPrice' => $this->cost_price,
            'salePrice' => $this->sale_price,
            'fixedShippingCost' => $this->fixed_shipping_cost,
            'productType' => $this->product_type,
            'optionSet' => $this->option_set,
            'optionSetAlign' => $this->option_set_align,
            'allowPurchase' => $this->allow_purchase,
            'stopProcessingRules' => $this->stop_processing_rules,
            'taxClass' => $this->tax_class,
            'taxProviderCode' => $this->tax_provider_code,
            'brandId' => (int) $this->brand_id,
            'categoryIds' =>  $this->category_id ? [$this->category_id] : [],
            'brand' => new BrandResource($this->whenLoaded('brand')),
            //'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'dimensions' => [
                'weight' => $dimensions['weight'] ?? 0,
                'width'  => $dimensions['width'] ?? 0,
                'height' => $dimensions['height'] ?? 0,
                'depth'  => $dimensions['depth'] ?? 0,
            ],
            'quantity' => $this->additional['quantity'] ?? $this->quantity,
            'trackInventory' => $this->track_inventory,
            'currentStock' => $this->current_stock,
            'lowStock' => $this->low_stock,
            'isFeatured' => $this->is_featured,
            'isVisible' => $this->visible_on_storefront,
            'showCondition' => $this->show_condition,
            'sortOrder' => $this->sort_order,
            'searchKeywords' => $this->search_keywords,
            'customFields' => collect($this->custom_fields ?? [])->mapWithKeys(function ($value, $key) {
                return [trim($key, "\"") => is_string($value) ? trim($value, "\"") : $value];
            }),
            'storeId' => $this->store_id,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            'image' => ProductImageResource::collection($this->whenLoaded('images')),
            'description' => optional($this->content)->description,
            'warranty' => optional($this->content)->warranty,
            'availabilityText' => optional($this->content)->availability_text,
            'templateLayout' => optional($this->content)->template_layout,
            'pageTitle' => optional($this->seo)->page_title,
            'productUrl' => optional($this->seo)->product_url,
            'metaDescription' => optional($this->seo)->meta_description,
            'mpn' => optional($this->identifiers)->mpn,
            'upc' => optional($this->identifiers)->upc,
            'gtin' => optional($this->identifiers)->gtin,
            'bpn' => optional($this->identifiers)->bpn,
            'categoryHierarchy' => $this->whenLoaded('categories', function () {
                $primaryCategory = $this->categories->first();
                return $primaryCategory ? $this->buildCategoryHierarchy($primaryCategory) : null;
            }),
            // 'savingAmount' => $savings,
            // 'savingPercent' => $savingPercent,
            // 'profit' => $profit,
            // 'profitMargin' => $profitMargin,
            'purchasabilityStatus' => $this->purchasability_status,
            'minPurchaseQuantity' => $this->min_purchase_quantity,
            'maxPurchaseQuantity' => $this->max_purchase_quantity,
            'relatedProductsEnabled' => $this->related_products_enabled,
            'schema' => $this->formatSchema() 
        ];
    }

    private function buildCategoryHierarchy($category)
    {
        $data = [];
        while ($category) {
            $data[] = [
                'id' => $category->id,
                'name' => $category->name,
            ];
            $category = $category->parent;
        }

        return array_reverse($data);
    }

    private function formatSchema(): array
{
    // Reviews
    $reviews = $this->reviews->map(function ($review) {
        return [
            "@type" => "Review",
            "author" => [
                "@type" => "Person",
                "name" => $review->user->name ?? 'Anonymous'
            ],
            "datePublished" => $review->created_at->format('M d, Y'),
            "reviewBody" => $review->comment ?? '',
            "name" => substr($review->comment ?? '', 0, 50) . '...',
            "reviewRating" => [
                "@type" => "Rating",
                "bestRating" => "5",
                "ratingValue" => $review->rating,
                "worstRating" => "1"
            ]
        ];
    });

    // Images
    $images = $this->images->map(fn($img) => url($img->path))->toArray();
    $primaryImage = $this->images->where('is_primary', 1)->first()?->path ? url($this->images->where('is_primary', 1)->first()->path) : ($images[0] ?? '');

    // Ratings
    $averageRating = round($this->reviews->avg('rating') ?? 0, 1);
    $reviewCount = $this->reviews->count();

    return [
        "@context" => "https://schema.org/",
        "@type" => "Product",
        "name" => $this->name,
        "sku" => $this->sku,
        "mpn" => $this->identifiers->mpn ?? $this->sku,
        "url" => url("/web/products/get-product/{$this->slug}"),
        "brand" => [
            "@type" => "Brand",
            "name" => $this->brand->name ?? '',
            "url" => url("/web/brands/brand/" . Str::slug($this->brand->name ?? ''))
        ],

       'description' => strip_tags(optional($this->content)->description),
        "image" => $primaryImage,
        // "additionalImage" => $images,
        "aggregateRating" => [
            "@type" => "AggregateRating",
            "ratingValue" => $averageRating,
            "reviewCount" => $reviewCount
        ],
        "review" => $reviews,
        "offers" => [
            "@type" => "Offer",
            "priceCurrency" => "USD",
            "price" => $this->price,
            "itemCondition" => "https://schema.org/NewCondition",
            "availability" => $this->current_stock > 0 
                              ? "https://schema.org/InStock" 
                              : "https://schema.org/OutOfStock",
            "url" => url("/web/products/get-product/{$this->slug}"),
            "priceValidUntil" => now()->addYear()->format('Y-m-d'),
            "hasMerchantReturnPolicy" => [
                "@type" => "MerchantReturnPolicy",
                "name" => "Return Policy",
                "url" => "https://nts-ecommerce.vercel.app/returnPolicy",
                "returnPolicyCategory" => "https://schema.org/MerchantReturnFiniteReturnWindow",
                "merchantReturnDays" => 30,
                "returnFees" => "FreeReturn",
                "returnMethod" => "https://schema.org/ReturnByMail",
                "applicableCountry" => "US"
            ],
            "shippingDetails" => [[
                "@type" => "OfferShippingDetails",
                "shippingDestination" => [[
                    "@type" => "DefinedRegion",
                    "addressCountry" => "US",
                    "addressRegion" => [
                        "AL","AK","AS","AZ","AR","AE","AA","AP","CA","CO","CT","DE","DC","FM",
                        "FL","GA","GU","HI","ID","IL","IN","IA","KS","KY","LA","ME","MH","MD",
                        "MA","MI","MN","MS","MO","MT","NE","NV","NH","NJ","NM","NY","NC","ND",
                        "MP","OH","OK","OR","PW","PA","PR","RI","SC","SD","TN","TX","UT","VT",
                        "VI","VA","WA","WV","WI","WY"
                    ]
                ]],
                "shippingRate" => [
                    "@type" => "MonetaryAmount",
                    "value" => $this->price,
                    "currency" => "USD"
                ],
                "deliveryTime" => [
                    "handlingTime" => [
                        "@type" => "QuantitativeValue",
                        "minValue" => 1,
                        "maxValue" => 2,
                        "unitCode" => "DAY"
                    ],
                    "transitTime" => [
                        "@type" => "QuantitativeValue",
                        "minValue" => 3,
                        "maxValue" => 5,
                        "unitCode" => "DAY"
                    ]
                ]
            ]]
        ],
        // "minPurchaseQuantity" => $this->min_purchase_quantity,
        // "maxPurchaseQuantity" => $this->max_purchase_quantity,
        // "relatedProductsEnabled" => $this->related_products_enabled,
        // "hasMerchantReturnPolicy" => [
        //     "@type" => "MerchantReturnPolicy",
        //     "url" => "https://nts-ecommerce.vercel.app/returnPolicy",
        //     "returnPolicyCategory" => "https://schema.org/MerchantReturnFiniteReturnWindow",
        //     "merchantReturnDays" => 30
        // ]
    ];
}


}
