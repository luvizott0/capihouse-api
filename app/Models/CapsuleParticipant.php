<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapsuleParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'memory_id',
        'user_id',
        'name',
        'role',
    ];

    public function memory(): BelongsTo
    {
        return $this->belongsTo(CapsuleMemory::class, 'memory_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Retorna o nome de exibição do participante (seja usuário cadastrado ou nome livre)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->user ? ($this->user->name ?? $this->user->username) : ($this->name ?? 'Anônimo');
    }
}
