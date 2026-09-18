<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'date',
        'user_id',
    ];

    protected $appends = [
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
        ];
    }

    public function getImageUrlAttribute(): ?string
    {
        $media = $this->media->first(fn ($m) => $m->collection_name === 'event_image') ?? $this->media->first();

        return $media ? $media->path : null;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function guests()
    {
        return $this->belongsToMany(User::class, 'event_users')->withPivot('status')->withTimestamps();
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function getImage(): ?string
    {
        $media = $this->media()->where('collection_name', 'event_image')->first();

        return $media ? $media->getUrl() : null;
    }

    public function getOwnerName(): string
    {
        return $this->owner->name ?? 'Unknown';
    }
}
