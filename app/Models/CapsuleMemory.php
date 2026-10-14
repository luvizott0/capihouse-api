<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CapsuleMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'occurred_at',
        'occurred_at_precision',
        'approximate_date',
        'location_name',
        'latitude',
        'longitude',
        'mood',
        'mood_emoji',
        'visibility',
        'is_favorite',
        'locked_until',
        'metadata',
    ];

    protected $appends = [
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date',
            'is_favorite' => 'boolean',
            'locked_until' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CapsuleTag::class, 'capsule_memory_tag', 'memory_id', 'tag_id')->withTimestamps();
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CapsuleParticipant::class, 'memory_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function getIsLockedAttribute(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Scope para buscar registros visíveis para o usuário autenticado
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('visibility', '!=', 'private')
                ->orWhere('user_id', $user->id)
                ->orWhereHas('participants', function (Builder $partQuery) use ($user) {
                    $partQuery->where('user_id', $user->id);
                });
        });
    }

    /**
     * Scope para filtrar memórias que aconteceram neste mesmo dia e mês em anos anteriores
     */
    public function scopeOnThisDay(Builder $query, ?int $month = null, ?int $day = null, ?int $excludeYear = null): Builder
    {
        $targetMonth = $month ?? Carbon::now()->month;
        $targetDay = $day ?? Carbon::now()->day;
        $targetYear = $excludeYear ?? Carbon::now()->year;

        return $query->whereNotNull('occurred_at')
            ->whereMonth('occurred_at', $targetMonth)
            ->whereDay('occurred_at', $targetDay)
            ->whereYear('occurred_at', '<', $targetYear);
    }
}
