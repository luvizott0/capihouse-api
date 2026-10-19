<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'description',
        'creator_id',
    ];

    protected $appends = [
        'members_count',
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
        return $this->members();
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function getMembersCountAttribute(): int
    {
        if ($this->relationLoaded('members')) {
            return $this->members->count();
        }

        return $this->members()->count();
    }
}
