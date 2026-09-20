<?php

namespace App\Models;

use App\Enums\UserRoles;
use App\Enums\UserStatuses;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

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
        'notification_preferences',
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
            'password' => 'hashed',
            'status' => UserStatuses::class,
            'role' => UserRoles::class,
            'birth' => 'date',
            'last_seen_at' => 'datetime',
            'theme' => 'array',
            'notification_preferences' => 'array',
        ];
    }

    public function getAvatarUrlAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (str_starts_with($value, '/')) {
            return $value;
        }
        if (str_contains($value, '/capihouse-media/')) {
            $relative = preg_replace('/^.*\/capihouse-media\//', '', $value);
            $disk = config('filesystems.default', 'public');

            return Storage::disk($disk)->url($relative);
        }
        if (str_contains($value, '/storage/')) {
            $relative = preg_replace('/^.*\/storage\//', '', $value);
            $disk = config('filesystems.default', 'public');

            return Storage::disk($disk)->url($relative);
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        $disk = config('filesystems.default', 'public');

        return Storage::disk($disk)->url(ltrim($value, '/'));
    }

    public function getBannerUrlAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (str_starts_with($value, '/')) {
            return $value;
        }
        if (str_contains($value, '/capihouse-media/')) {
            $relative = preg_replace('/^.*\/capihouse-media\//', '', $value);
            $disk = config('filesystems.default', 'public');

            return Storage::disk($disk)->url($relative);
        }
        if (str_contains($value, '/storage/')) {
            $relative = preg_replace('/^.*\/storage\//', '', $value);
            $disk = config('filesystems.default', 'public');

            return Storage::disk($disk)->url($relative);
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        $disk = config('filesystems.default', 'public');

        return Storage::disk($disk)->url(ltrim($value, '/'));
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

    public function mentionedPosts()
    {
        return $this->belongsToMany(Post::class, 'post_mentions')->withTimestamps();
    }

    public function mentionedComments()
    {
        return $this->belongsToMany(PostComment::class, 'post_comment_mentions')->withTimestamps();
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
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
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

    public static function defaultNotificationPreferences(): array
    {
        return [
            'likes' => true,
            'comments' => true,
            'mentions' => true,
            'group_invites' => true,
            'event_invites' => true,
        ];
    }

    public function getEffectiveNotificationPreferences(): array
    {
        $prefs = $this->notification_preferences ?? [];

        return array_merge(self::defaultNotificationPreferences(), $prefs);
    }

    public function wantsNotificationFor(string $type): bool
    {
        $category = match ($type) {
            'post_like', 'comment_like' => 'likes',
            'post_comment', 'comment_reply', 'comment', 'reply' => 'comments',
            'post_mention', 'comment_mention' => 'mentions',
            'group_invite' => 'group_invites',
            'event_invite', 'event_rsvp' => 'event_invites',
            default => null,
        };

        if ($category === null) {
            return true;
        }

        $prefs = $this->getEffectiveNotificationPreferences();

        return (bool) ($prefs[$category] ?? true);
    }
}
