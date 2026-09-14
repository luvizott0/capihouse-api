<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'content',
        'likes_count',
        'comments_count',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function likes()
    {
        return $this->hasMany(PostLike::class);
    }

    public function hashtags()
    {
        return $this->belongsToMany(Hashtag::class);
    }

    public function comments()
    {
        return $this->hasMany(PostComment::class);
    }

    public function feeling()
    {
        return $this->hasOne(Feeling::class);
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
        return $this->feeling ? $this->feeling->emoji . ' ' . $this->feeling->name : null;
    }
}
