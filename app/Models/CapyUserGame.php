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
        'aspect_ratings',
        'review',
        'achievements_earned',
        'achievements_total',
        'gamerscore_earned',
        'gamerscore_total',
        'source',
        'is_favorite',
        'started_at',
        'completed_at',
        'last_played_at',
    ];

    protected $casts = [
        'in_list' => 'boolean',
        'list_order' => 'integer',
        'hours_played' => 'float',
        'platform_hours' => 'array',
        'rating' => 'float',
        'aspect_ratings' => 'array',
        'achievements_earned' => 'integer',
        'achievements_total' => 'integer',
        'gamerscore_earned' => 'integer',
        'gamerscore_total' => 'integer',
        'is_favorite' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_played_at' => 'datetime',
    ];

    protected $appends = [
        'effective_status',
        'progress_percentage',
    ];

    /**
     * Retorna o status efetivo, aplicando a regra de pausa automática (> 30 dias sem atualizar)
     */
    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status === 'playing' && $this->updated_at && $this->updated_at->lt(now()->subDays(30))) {
            return 'on_hold';
        }

        return (string) ($this->status ?? 'plan_to_play');
    }

    /**
     * Retorna a porcentagem de conclusão baseada exclusivamente em conquistas reais sincronizadas
     */
    public function getProgressPercentageAttribute(): int
    {
        // 100% vem exclusivamente de platinado (mastered sincronizado) ou de ter todas as conquistas
        if ($this->status === 'mastered') {
            return 100;
        }

        if ($this->achievements_total > 0) {
            return (int) min(100, round((($this->achievements_earned ?? 0) / $this->achievements_total) * 100));
        }

        if ($this->gamerscore_total > 0) {
            return (int) min(100, round((($this->gamerscore_earned ?? 0) / $this->gamerscore_total) * 100));
        }

        if (! empty($this->game?->raw_data['achievements_count']) && $this->game->raw_data['achievements_count'] > 0) {
            return (int) min(100, round((($this->achievements_earned ?? 0) / $this->game->raw_data['achievements_count']) * 100));
        }

        return 0;
    }

    /**
     * Atualiza as horas de uma plataforma e recalcula a soma total
     */
    public function setPlatformHours(string $platform, float $hours): void
    {
        $targetPlat = trim($platform);
        if (preg_match('/^(pc|windows|win32|pc\s*\(.*\)|steam.*)$/i', $targetPlat)) {
            $targetPlat = 'Steam';
        }

        $ph = $this->platform_hours ?: [];

        // Limpa chaves legadas de PC duplicadas quando salvar Steam
        if ($targetPlat === 'Steam') {
            unset($ph['PC'], $ph['PC (Steam)'], $ph['PC (Windows)'], $ph['Windows']);
        }

        $ph[$targetPlat] = max(0, $hours);
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
