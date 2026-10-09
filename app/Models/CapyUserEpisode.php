<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapyUserEpisode extends Model
{
    use HasFactory;

    protected $table = 'capy_user_episodes';

    protected $fillable = [
        'user_id',
        'series_id',
        'season_id',
        'episode_id',
        'is_watched',
        'watched_at',
        'rating',
    ];

    protected $casts = [
        'is_watched' => 'boolean',
        'watched_at' => 'datetime',
        'rating' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CapySeries::class, 'series_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(CapySeason::class, 'season_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(CapyEpisode::class, 'episode_id');
    }
}
