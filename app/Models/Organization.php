<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'user_id',
        'url',
        'yandex_id',
        'name',
        'rating',
        'reviews_count',
        'ratings_count',
        'parsed_at',
        'parse_status',
        'parse_error',
    ];

    protected $casts = [
        'rating'        => 'float',
        'reviews_count' => 'integer',
        'ratings_count' => 'integer',
        'parsed_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->orderByDesc('reviewed_at');
    }
}
