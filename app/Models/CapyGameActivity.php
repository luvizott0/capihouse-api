<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapyGameActivity extends Model
{
    use HasFactory;

    protected $table = 'capy_game_activities';

    protected $fillable = [
        'user_id',
        'game_id',
        'type', // 'achievement', 'status_change', 'review', 'rating'
        'title',
        'content',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
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
