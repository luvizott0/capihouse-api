<?php

namespace App\Models;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'banner_url',
        'avatar_url',
        'status',
        'role',
        'bio',
        'birth',
        'instagram',
        'spotify',
        'last_seen_at',
        'theme',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'status'            => UserStatuses::class,
            'role'              => UserRoles::class,
            'birth'             => 'date',
            'last_seen_at'      => 'datetime',
            'theme'             => 'array',
        ];
    }

    public function getAvatarUrlAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (str_starts_with($value, 'http://capihouse.bmo/storage/')) {
            $relative = substr($value, strlen('http://capihouse.bmo/storage/'));
            $disk = config('filesystems.default', 'public');
            return Storage::disk($disk)->url($relative);
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        $disk = config('filesystems.default', 'public');
        $clean = preg_replace('/^\/?storage\//', '', $value);
        return Storage::disk($disk)->url(ltrim($clean, '/'));
    }

    public function getBannerUrlAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (str_starts_with($value, 'http://capihouse.bmo/storage/')) {
            $relative = substr($value, strlen('http://capihouse.bmo/storage/'));
            $disk = config('filesystems.default', 'public');
            return Storage::disk($disk)->url($relative);
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        $disk = config('filesystems.default', 'public');
        $clean = preg_replace('/^\/?storage\//', '', $value);
        return Storage::disk($disk)->url(ltrim($clean, '/'));
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function postLikes()
    {
        return $this->hasMany(PostLike::class);
    }

    public function postComments()
    {
        return $this->hasMany(PostComment::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function invitedEvents()
    {
        return $this->belongsToMany(Event::class, 'event_users')->withPivot('status')->withTimestamps();
    }

    public function interests()
    {
        return $this->belongsToMany(Interest::class);
    }

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_users')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function acceptedGroups()
    {
        return $this->belongsToMany(Group::class, 'group_users')
            ->wherePivot('status', 'accepted')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function appNotifications()
    {
        return $this->hasMany(AppNotification::class);
    }

    public function avatar()
    {
        return $this->morphOne(Media::class, 'mediable')->where('collection_name', 'avatar');
    }

    public function banner()
    {
        return $this->morphOne(Media::class, 'mediable')->where('collection_name', 'banner');
    }

    public function initials(): string
    {
        $words = explode(' ', $this->name);
        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        }
        return mb_strtoupper(mb_substr($this->name, 0, 2));
    }

    public function isApproved(): bool
    {
        return $this->status === UserStatuses::APPROVED;
    }

    public function isBanned(): bool
    {
        return $this->status === UserStatuses::BANNED;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatuses::PENDING;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRoles::Admin;
    }
}
