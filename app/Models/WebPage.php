<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WebPage extends Model
{
     use HasFactory;
     protected $connection = 'tenant';

    protected $table = 'web_pages';

    protected $fillable = [
        'store_id',
        'page_name',
        'page_url',
        'page_title',
        'page_content',
        'page_type',
        'template_layout_file',
        'meta_keywords',
        'meta_description',
        'search_keywords',
        'display_as_home_page',
        'restrict_to_customers_only',
        'show_in_navigation',
        'is_page_visible',
        'sort_order',
        'parent_page',
        'link',
        'emailQuestionsTo',
        'showTheseFields',
        'rawHtml',
        'slugWithUrl',
    ];

    // ✅ Type casting for boolean fields
    protected $casts = [
        'display_as_home_page' => 'boolean',
        'restrict_to_customers_only' => 'boolean',
        'show_in_navigation' => 'boolean',
        'is_page_visible' => 'boolean',
        'showTheseFields' => 'array'
    ];

    // ✅ Example relationship (if your stores are in a stores table)
    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
