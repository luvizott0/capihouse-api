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
        'in_list',
        'list_order',
        'platform',
        'hours_played',
        'platform_hours',
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
        'in_list' => 'boolean',
        'list_order' => 'integer',
        'hours_played' => 'float',
        'platform_hours' => 'array',
        'rating' => 'float',
        'achievements_earned' => 'integer',
        'achievements_total' => 'integer',
        'gamerscore_earned' => 'integer',
        'gamerscore_total' => 'integer',
        'is_favorite' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Retorna o status efetivo, aplicando a regra de pausa automática (> 30 dias sem atualizar)
     */
    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status === 'playing' && $this->updated_at && $this->updated_at->lt(now()->subDays(30))) {
            return 'on_hold';
        }

        return $this->status;
    }

    /**
     * Atualiza as horas de uma plataforma e recalcula a soma total
     */
    public function setPlatformHours(string $platform, float $hours): void
    {
        $ph = $this->platform_hours ?: [];
        $ph[$platform] = max(0, $hours);
        $this->platform_hours = $ph;
        $this->hours_played = round(array_sum($ph), 1);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(CapyGame::class, 'game_id');
    }
}
