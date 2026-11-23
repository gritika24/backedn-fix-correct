<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CafeReward extends Model
{
    protected $fillable = [
        'cafe_id',
        'title',
        'description',
        'reward_type',
        'required_visits',
        'is_active',
        'is_repeatable',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_repeatable' => 'boolean',
        'required_visits' => 'integer',
    ];

    // Each reward belongs to one café.
    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    // Each reward can be unlocked by many customers.
    public function userRewards(): HasMany
    {
        return $this->hasMany(UserReward::class);
    }
}
