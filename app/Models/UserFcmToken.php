<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFcmToken extends Model
{
    protected $fillable = [
        'fcm_token',
        'platform',
        'device_id',
        'app_version',
        'timezone',
        'locale',
    ];

    protected $hidden = [
        'fcm_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
