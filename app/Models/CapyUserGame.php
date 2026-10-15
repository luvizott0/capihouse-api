<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapyUserGame extends Model
{
    use HasFactory;

    protected $table = 'capy_user_games';

    protected $fillable = [
        'user_id',
        'game_id',
        'status',
        'platform',
        'hours_played',
        'rating',
        'review',
        'achievements_earned',
        'achievements_total',
        'gamerscore_earned',
        'gamerscore_total',
        'source',
        'is_favorite',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'hours_played' => 'float',
        'rating' => 'float',
        'achievements_earned' => 'integer',
        'achievements_total' => 'integer',
        'gamerscore_earned' => 'integer',
        'gamerscore_total' => 'integer',
        'is_favorite' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(CapyGame::class, 'game_id');
    }
}
