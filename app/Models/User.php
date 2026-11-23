<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'profile_image', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // A user can have many café visits
    public function cafeVisits(): HasMany
    {
        return $this->hasMany(CafeVisit::class);
    }

    // A user can earn many badges.
    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    // A user can unlock many rewards.
    public function userRewards(): HasMany
    {
        return $this->hasMany(UserReward::class);
    }

    // A user can redeem rewards as café owner or staff.
    public function redeemedRewards(): HasMany
    {
        return $this->hasMany(UserReward::class, 'redeemed_by');
    }

    // A user can favourite many cafés.
    public function favourites(): HasMany
    {
        return $this->hasMany(CafeFavourite::class);
    }

    // A user can receive many CaféVerse notifications.
    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    // A café owner owns many cafés.
    public function cafes(): HasMany
    {
        return $this->hasMany(Cafe::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isCafeOwner(): bool
    {
        return $this->role === 'cafe_owner';
    }
}
