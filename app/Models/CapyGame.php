<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CapyGame extends Model
{
    use HasFactory;

    protected $table = 'capy_games';

    protected $fillable = [
        'title',
        'slug',
        'external_source',
        'external_id',
        'cover_url',
        'banner_url',
        'summary',
        'developer',
        'publisher',
        'release_date',
        'platforms',
        'genres',
        'raw_data',
    ];

    protected $casts = [
        'platforms' => 'array',
        'genres' => 'array',
        'raw_data' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($game) {
            if (empty($game->slug)) {
                $game->slug = Str::slug($game->title);
            }
        });
    }

    public function userGames(): HasMany
    {
        return $this->hasMany(CapyUserGame::class, 'game_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CapyGameActivity::class, 'game_id');
    }
}
