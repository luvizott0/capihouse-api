<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapyUserWatchProgress extends Model
{
    protected $table = 'capy_user_watch_progress';

    protected $fillable = [
        'user_id',
        'media_id',
        'season_index',
        'episode_index',
        'time',
        'duration',
        'completed',
        'last_watched_at',
    ];

    protected $casts = [
        'season_index' => 'integer',
        'episode_index' => 'integer',
        'time' => 'integer',
        'duration' => 'integer',
        'completed' => 'boolean',
        'last_watched_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
