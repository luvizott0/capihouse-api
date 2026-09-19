<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyRecap extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'post_id',
        'year_month',
        'total_feelings',
        'emoji_summary',
        'top_emojis',
    ];

    protected $casts = [
        'top_emojis' => 'array',
        'total_feelings' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
