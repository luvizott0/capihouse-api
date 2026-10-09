<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CapySeries extends Model
{
    use HasFactory;

    protected $table = 'capy_series';

    protected $fillable = [
        'tmdb_id',
        'name',
        'original_name',
        'overview',
        'poster_path',
        'backdrop_path',
        'first_air_date',
        'status',
        'vote_average',
        'number_of_seasons',
        'number_of_episodes',
        'genres',
        'synced_at',
    ];

    protected $casts = [
        'genres' => 'array',
        'first_air_date' => 'date',
        'synced_at' => 'datetime',
        'vote_average' => 'float',
    ];

    protected $appends = [
        'poster_url',
        'backdrop_url',
    ];

    public function seasons(): HasMany
    {
        return $this->hasMany(CapySeason::class, 'series_id')->orderBy('season_number');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(CapyEpisode::class, 'series_id');
    }

    public function userSeries(): HasMany
    {
        return $this->hasMany(CapyUserSeries::class, 'series_id');
    }

    public function userEpisodes(): HasMany
    {
        return $this->hasMany(CapyUserEpisode::class, 'series_id');
    }

    /**
     * URL completa do poster TMDB
     */
    public function getPosterUrlAttribute(): ?string
    {
        return $this->poster_path ? "https://image.tmdb.org/t/p/w500{$this->poster_path}" : null;
    }

    /**
     * URL completa do backdrop TMDB
     */
    public function getBackdropUrlAttribute(): ?string
    {
        return $this->backdrop_path ? "https://image.tmdb.org/t/p/original{$this->backdrop_path}" : null;
    }
}
