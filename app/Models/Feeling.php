<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Feeling extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'post_id',
        'name',
        'emoji',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
