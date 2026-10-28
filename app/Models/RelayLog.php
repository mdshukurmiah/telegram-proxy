<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

final class RelayLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'bot_id',
        'method',
        'http_status',
        'duration_ms',
        'error',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        $days = (int) config('relay.log_retention_days', 14);

        return self::where('created_at', '<=', now()->subDays($days));
    }
}
