<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class CapsuleTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'color',
    ];

    protected static function booted(): void
    {
        static::creating(function (CapsuleTag $tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function memories(): BelongsToMany
    {
        return $this->belongsToMany(CapsuleMemory::class, 'capsule_memory_tag', 'tag_id', 'memory_id');
    }
}
