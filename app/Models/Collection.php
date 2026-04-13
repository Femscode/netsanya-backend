<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'auth_type',
        'auth_config',
        'variables',
    ];

    protected $casts = [
        'auth_config' => 'array',
        'variables' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(SavedRequest::class);
    }
}
