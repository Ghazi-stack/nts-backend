<?php

namespace App\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BlogPost extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id',
        'title',
        'body',
        'author',
        'tags',
        'thumbnail_image',
        'post_url',
        'meta_description',
    ];

    protected $casts = [
        'tags' => 'array', 
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
