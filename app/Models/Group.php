<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'creator_id',
    ];

    protected $appends = [
        'image_url',
        'members_count',
        'is_member',
        'membership_status',
        'my_role',
        'unread_messages_count',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'group_users')
            ->withPivot(['role', 'status', 'last_read_at'])
            ->withTimestamps();
    }

    public function acceptedMembers()
    {
        return $this->belongsToMany(User::class, 'group_users')
            ->wherePivot('status', 'accepted')
            ->withPivot(['role', 'status', 'last_read_at'])
            ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(GroupMessage::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function getImageUrlAttribute(): ?string
    {
        $media = $this->media->first(fn ($m) => $m->collection_name === 'group_photo') ?? $this->media->first();

        return $media ? $media->path : null;
    }

    public function getMembersCountAttribute(): int
    {
        if ($this->relationLoaded('acceptedMembers')) {
            return $this->acceptedMembers->count();
        }

        return $this->acceptedMembers()->count();
    }

    public function getIsMemberAttribute(): bool
    {
        $userId = auth()->id();
        if (! $userId) {
            return false;
        }

        if ($this->relationLoaded('members')) {
            return $this->members->contains(function ($user) use ($userId) {
                return $user->id === $userId && $user->pivot->status === 'accepted';
            });
        }

        return $this->members()
            ->where('users.id', $userId)
            ->wherePivot('status', 'accepted')
            ->exists();
    }

    public function getMembershipStatusAttribute(): ?string
    {
        $userId = auth()->id();
        if (! $userId) {
            return null;
        }

        if ($this->relationLoaded('members')) {
            $user = $this->members->firstWhere('id', $userId);

            return $user ? $user->pivot->status : null;
        }

        $record = $this->members()->where('users.id', $userId)->first();

        return $record ? $record->pivot->status : null;
    }

    public function getMyRoleAttribute(): ?string
    {
        $userId = auth()->id();
        if (! $userId) {
            return null;
        }

        if ($this->relationLoaded('members')) {
            $user = $this->members->firstWhere('id', $userId);

            return $user ? $user->pivot->role : null;
        }

        $record = $this->members()->where('users.id', $userId)->first();

        return $record ? $record->pivot->role : null;
    }

    public function getUnreadMessagesCountAttribute(): int
    {
        if (array_key_exists('unread_messages_count', $this->attributes)) {
            return (int) $this->attributes['unread_messages_count'];
        }

        $userId = auth()->id();
        if (! $userId) {
            return 0;
        }

        if ($this->relationLoaded('members')) {
            $member = $this->members->firstWhere('id', $userId);
        } else {
            $member = $this->members()->where('users.id', $userId)->first();
        }

        if (! $member || $member->pivot->status !== 'accepted') {
            return 0;
        }

        $lastReadAt = $member->pivot->last_read_at;

        $query = $this->messages()
            ->where('user_id', '!=', $userId)
            ->whereNull('deleted_at');

        if ($lastReadAt) {
            $query->where('created_at', '>', $lastReadAt);
        }

        return $query->count();
    }
}
