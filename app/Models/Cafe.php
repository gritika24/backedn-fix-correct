<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Storage;

class Cafe extends Model
{
    /** Hours after which an owner's crowd update is considered stale. */
    public const CROWD_HOURS = 3;

    /** Columns that store a single uploaded file path. */
    public const SINGLE_FILE_FIELDS = ['logo_path', 'cover_image_path'];

    /** Columns that store a JSON list of uploaded file paths. */
    public const MULTI_FILE_FIELDS = ['cafe_photos', 'food_photos', 'menu_images'];

    protected $appends = ['current_crowd_level'];

    /**
     * The check-out QR secret must never be sent to customers, otherwise
     * anyone could complete a visit without being at the café.
     * Only the owner's /my-cafe-qrs endpoint reveals it (makeVisible).
     */
    protected $hidden = ['qr_token'];

    protected $fillable = [
        'user_id',

        // Café Information
        'name',
        'description',
        'contact_number',
        'email',
        'logo_path',
        'cover_image_path',

        // Location
        'address',
        'latitude',
        'longitude',
        'maps_link',

        // Opening Information
        'opening_time',
        'closing_time',
        'operating_days',
        'budget_range',

        // Ambience and Experience
        'ambience',
        'mood',
        'activities',
        'seating',
        'facilities',
        'social_setting',

        // Photographs
        'cafe_photos',
        'food_photos',
        'menu_images',

        // Approval
        'status',
        'rejection_reason',
        'reviewed_at',
        'crowd_level',
        'crowd_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'operating_days' => 'array',
            'ambience' => 'array',
            'mood' => 'array',
            'activities' => 'array',
            'seating' => 'array',
            'facilities' => 'array',
            'social_setting' => 'array',
            'cafe_photos' => 'array',
            'food_photos' => 'array',
            'menu_images' => 'array',
            'reviewed_at' => 'datetime',
            'crowd_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // When a café is deleted, remove every file that belonged to it
        // (café images plus customers' visit memories and review media,
        // whose database rows are removed by cascading foreign keys).
        static::deleting(function (Cafe $cafe) {
            $cafe->pendingFileDeletes = $cafe->allStoredFilePaths();
        });

        static::deleted(function (Cafe $cafe) {
            Storage::disk('public')->delete($cafe->pendingFileDeletes);
        });
    }

    /** @var array<int, string> */
    protected array $pendingFileDeletes = [];

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(CafeVisit::class);
    }

    /** Reviews are attached to visits, so they are reached through them. */
    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(CafeReview::class, CafeVisit::class, 'cafe_id', 'cafe_visit_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(CafeReward::class);
    }

    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function userRewards(): HasMany
    {
        return $this->hasMany(UserReward::class);
    }

    public function favourites(): HasMany
    {
        return $this->hasMany(CafeFavourite::class);
    }

    /** Crowd level shown to customers; null once the owner's update is older than CROWD_HOURS. */
    public function getCurrentCrowdLevelAttribute(): ?string
    {
        if (! $this->crowd_level || ! $this->crowd_updated_at) {
            return null;
        }

        return $this->crowd_updated_at->lt(now()->subHours(self::CROWD_HOURS))
            ? null
            : $this->crowd_level;
    }

    /** Paths of the café's own uploaded images. */
    public function imagePaths(): array
    {
        $paths = [];

        foreach (self::SINGLE_FILE_FIELDS as $field) {
            $paths[] = $this->{$field};
        }

        foreach (self::MULTI_FILE_FIELDS as $field) {
            $paths = array_merge($paths, (array) ($this->{$field} ?? []));
        }

        return array_values(array_filter($paths, fn ($path) => is_string($path) && $path !== ''));
    }

    /** Café images plus all memory and review files linked to its visits. */
    private function allStoredFilePaths(): array
    {
        $memoryPaths = CafeVisitMemory::whereIn('cafe_visit_id', $this->visits()->select('id'))
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->all();

        $reviewPaths = CafeReview::whereIn('cafe_visit_id', $this->visits()->select('id'))
            ->pluck('photos')
            ->flatten(1)
            ->pluck('file_path')
            ->filter()
            ->all();

        return array_merge($this->imagePaths(), $memoryPaths, $reviewPaths);
    }
}
