<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapyUserSeries extends Model
{
    use HasFactory;

    protected $table = 'capy_user_series';

    protected $fillable = [
        'user_id',
        'series_id',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CapySeries::class, 'series_id');
    }
}
