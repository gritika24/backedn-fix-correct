<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PersonalActivity extends Model
{
    // Fields that can be filled when creating or updating an activity
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'description',
        'tracking_type',
        'icon',
        'target_duration_minutes',
        'completed_duration_seconds',
        'status',
        'deadline',
        'completed_at',
    ];

    // Automatically convert these fields into the correct data types
    protected function casts(): array
    {
        return [
            'target_duration_minutes' => 'integer',
            'completed_duration_seconds' => 'integer',
            'deadline' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    // The activity belongs to one user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // One activity can have multiple checklist items
    public function checklistItems(): HasMany
    {
        return $this->hasMany(PersonalActivityChecklistItem::class);
    }

    // One activity can have multiple timer sessions
    public function timerSessions(): HasMany
    {
        return $this->hasMany(PersonalActivityTimerSession::class);
    }

    // One activity can earn one badge
    public function badge(): HasOne
    {
        return $this->hasOne(PersonalActivityBadge::class);
    }

    /**
     * Marks the user's unfinished activities whose deadline has passed as
     * "abandoned" (shown as "Expired" in the app). A timer that is running
     * right now is left alone until it is paused, so no time is lost.
     * The deadline day itself still counts.
     */
    public static function expireOverdue(int $userId): int
    {
        return static::where('user_id', $userId)
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', today())
            ->where(fn ($query) => $query
                ->where('status', 'paused')
                ->orWhere(fn ($q) => $q->where('tracking_type', 'checklist')->where('status', 'active')))
            ->update(['status' => 'abandoned']);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'abandoned'], true);
    }
}
