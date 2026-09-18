<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'user_id',
        'content',
        'edited_at',
        'deleted_at',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'is_edited',
        'is_deleted',
    ];

    public function getIsEditedAttribute(): bool
    {
        return ! is_null($this->edited_at) && is_null($this->deleted_at);
    }

    public function getIsDeletedAttribute(): bool
    {
        return ! is_null($this->deleted_at);
    }

    public function getContentAttribute(?string $value): string
    {
        if (! is_null($this->deleted_at)) {
            return 'mensagem deletada';
        }

        return $value ?? '';
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
