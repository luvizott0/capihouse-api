<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapyEpisode extends Model
{
    use HasFactory;

    protected $table = 'capy_episodes';

    protected $fillable = [
        'series_id',
        'season_id',
        'tmdb_id',
        'season_number',
        'episode_number',
        'name',
        'overview',
        'still_path',
        'air_date',
        'runtime',
    ];

    protected $casts = [
        'season_number' => 'integer',
        'episode_number' => 'integer',
        'runtime' => 'integer',
        'air_date' => 'date',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(CapySeries::class, 'series_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(CapySeason::class, 'season_id');
    }

    public function userEpisodes(): HasMany
    {
        return $this->hasMany(CapyUserEpisode::class, 'episode_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CapyEpisodeComment::class, 'episode_id')->latest();
    }

    public function getStillUrlAttribute(): ?string
    {
        return $this->still_path ? "https://image.tmdb.org/t/p/w500{$this->still_path}" : null;
    }
}
