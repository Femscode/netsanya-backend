<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'collection_id',
        'position',
        'name',
        'method',
        'url',
        'query',
        'headers',
        'body_type',
        'body',
        'body_text',
        'body_form',
        'auth_type',
        'auth_config',
        'last_response',
    ];

    protected $casts = [
        'query' => 'array',
        'headers' => 'array',
        'body' => 'array',
        'body_form' => 'array',
        'auth_config' => 'array',
        'last_response' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
