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

    protected static function booted(): void
    {
        static::deleting(function (Media $media) {
            $raw = $media->getRawOriginal('path') ?? $media->attributes['path'] ?? null;
            if ($raw && !str_starts_with($raw, 'http://') && !str_starts_with($raw, 'https://')) {
                $clean = preg_replace('/^\/?storage\//', '', $raw);
                $disk = config('filesystems.default', 'public');
                try {
                    \Illuminate\Support\Facades\Storage::disk($disk)->delete(ltrim($clean, '/'));
                } catch (\Throwable $e) {
                    // Ignore deletion errors on model cleanup
                }
            }
        });
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
        $disk = config('filesystems.default', 'public');
        return \Illuminate\Support\Facades\Storage::disk($disk)->url(ltrim($clean, '/'));
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path;
    }

    public function getUrl(): string
    {
        return $this->path ?? '';
    }

    public function getRawPath(): ?string
    {
        $raw = $this->getRawOriginal('path') ?? $this->attributes['path'] ?? null;
        if (empty($raw)) {
            return null;
        }
        return preg_replace('/^\/?storage\//', '', $raw);
    }
}
