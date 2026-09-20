<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'group_id',
        'event_id',
        'content',
        'likes_count',
        'comments_count',
        'category',
        'entertainment_type',
        'external_source',
        'external_id',
        'metadata',
        'repost_of_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function repostedPost()
    {
        return $this->belongsTo(Post::class, 'repost_of_id');
    }

    public function reposts()
    {
        return $this->hasMany(Post::class, 'repost_of_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function likes()
    {
        return $this->hasMany(PostLike::class);
    }

    public function hashtags()
    {
        return $this->belongsToMany(Hashtag::class);
    }

    public function mentions()
    {
        return $this->belongsToMany(User::class, 'post_mentions')->withTimestamps();
    }

    public function comments()
    {
        return $this->hasMany(PostComment::class);
    }

    public function feeling()
    {
        return $this->hasOne(Feeling::class);
    }

    public function poll()
    {
        return $this->hasOne(Poll::class);
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function getLikesCount(): int
    {
        return $this->likes_count;
    }

    public function getCommentsCount(): int
    {
        return $this->comments_count;
    }

    public function getMood(): ?string
    {
        return $this->feeling ? $this->feeling->emoji.' '.$this->feeling->name : null;
    }
}
