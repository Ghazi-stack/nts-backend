<?php

namespace App\Models;

use App\Models\User;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TransactionalEmailTemplate extends Model
{
    use HasFactory;
    protected $fillable = [
        'store_id','key','name','enabled',
        'from_name','from_email','reply_to','cc','bcc',
        'subject','body_html','body_text',
        'variables','locale','updated_by',
    ];

    protected $casts = [
        'enabled'   => 'boolean',
        'variables' => 'array',
    ];

    /* ----------------- Relationships ----------------- */
    public function store()
    {
        // assume you have App\Models\Store
        return $this->belongsTo(Store::class);
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ----------------- Scopes ----------------- */
    public function scopeOfStore(Builder $q, int $storeId): Builder
    {
        return $q->where('store_id', $storeId);
    }

    public function scopeKey(Builder $q, string $key): Builder
    {
        return $q->where('key', $key);
    }
}
