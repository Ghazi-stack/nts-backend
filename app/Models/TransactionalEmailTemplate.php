<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\TransactionalEmailPhrase;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TransactionalEmailTemplate extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'store_id',
        'key',
        'name',
        'description',
        'enabled',
        'from_name',
        'from_email',
        'reply_to',
        'cc',
        'bcc',
        'subject',
        'body_html',
        'body_text',
        'variables',
        'locale',
        'updated_by',
    ];

    protected $casts = [
        'enabled'   => 'boolean',
        'variables' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function phrases()
    {
        return $this->hasMany(TransactionalEmailPhrase::class, 'template_id');
    }

    public function scopeOfStore(Builder $q, int $storeId): Builder
    {
        return $q->where('store_id', $storeId);
    }

    public function scopeKey(Builder $q, string $key): Builder
    {
        return $q->where('key', $key);
    }
}
