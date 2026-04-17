<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['post_id', 'type', 'path', 'mime_type', 'size_bytes'])]
class PostMedia extends Model
{
    use HasFactory;

    /**
     * Get the post associated with this media.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
