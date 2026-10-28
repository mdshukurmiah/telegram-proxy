<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WebhookTarget extends Model
{
    protected $fillable = [
        'bot_id',
        'target_url',
        'target_secret',
        'route_secret',
        'telegram_secret',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_secret' => 'encrypted',
            'telegram_secret' => 'encrypted',
            'active' => 'boolean',
        ];
    }
}
