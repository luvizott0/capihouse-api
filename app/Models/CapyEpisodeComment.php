<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapyEpisodeComment extends Model
{
    use HasFactory;

    protected $table = 'capy_episode_comments';

    protected $fillable = [
        'episode_id',
        'user_id',
        'content',
        'is_spoiler',
    ];

    protected $casts = [
        'is_spoiler' => 'boolean',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(CapyEpisode::class, 'episode_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(CapyEpisodeCommentLike::class, 'comment_id');
    }
}
