<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CafeVisit extends Model
{
    protected $fillable = [
        'user_id',
        'cafe_id',
        'check_in_at',
        'check_out_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
        ];
    }

    // A visit belongs to one user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // A visit belongs to one café
    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(CafeVisitMemory::class);
    }

    // A visit has one review
    public function review(): HasOne
    {
        return $this->hasOne(CafeReview::class);
    }

    /**
     * Checked in but not yet checked out. Visits left open longer than
     * cafeverse.max_visit_hours are treated as abandoned and ignored.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->where('check_in_at', '>=', now()->subHours(config('cafeverse.max_visit_hours')));
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('check_in_at')->whereNotNull('check_out_at');
    }

    /** Whole minutes between check-in and check-out (0 while still active). */
    public function durationMinutes(): int
    {
        if (! $this->check_in_at || ! $this->check_out_at) {
            return 0;
        }

        return (int) round($this->check_in_at->diffInMinutes($this->check_out_at, true));
    }
}
