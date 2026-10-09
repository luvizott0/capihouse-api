<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapySeason extends Model
{
    use HasFactory;

    protected $table = 'capy_seasons';

    protected $fillable = [
        'series_id',
        'tmdb_id',
        'season_number',
        'name',
        'overview',
        'poster_path',
        'air_date',
        'episode_count',
    ];

    protected $casts = [
        'season_number' => 'integer',
        'episode_count' => 'integer',
        'air_date' => 'date',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(CapySeries::class, 'series_id');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(CapyEpisode::class, 'season_id')->orderBy('episode_number');
    }

    public function userEpisodes(): HasMany
    {
        return $this->hasMany(CapyUserEpisode::class, 'season_id');
    }

    public function getPosterUrlAttribute(): ?string
    {
        return $this->poster_path ? "https://image.tmdb.org/t/p/w500{$this->poster_path}" : null;
    }
}
