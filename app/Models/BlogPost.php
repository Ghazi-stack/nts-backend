<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BlogPost extends Model
{
    use HasFactory;
    protected $connection = 'tenant';
    protected $fillable = [
        'store_id',
        'title',
        'slug',
        'body',
        'author',
        'tags',
        'thumbnail_image',
        'post_url',
        'meta_description',
        'is_draft',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_draft' => 'boolean',
    ];
    protected $appends = ['thumbnail_url'];
    public function getThumbnailUrlAttribute()
    {
        if ($this->thumbnail_image && file_exists(public_path('blog_images/' . $this->thumbnail_image))) {
            return url('blog_images/' . $this->thumbnail_image);
        }
        return null;
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
