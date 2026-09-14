<?php

namespace App\Models;

use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'path',
        'type',
        'collection_name',
        'mediable_id',
        'mediable_type',
    ];

    protected $appends = [
        'url',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
        ];
    }

    public function mediable()
    {
        return $this->morphTo();
    }

    public function getPathAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        $clean = preg_replace('/^\/?storage\//', '', $value);
        return asset('storage/' . ltrim($clean, '/'));
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path;
    }

    public function getUrl(): string
    {
        return $this->path ?? '';
    }
}
