<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppAnalyticsBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property bool $enabled
 * @property list<string> $public_hosts
 * @property-read App $app
 */
class AppAnalyticsBinding extends Model
{
    /** @use HasFactory<AppAnalyticsBindingFactory> */
    use HasFactory;

    #[\Override]
    protected $table = 'app_analytics_bindings';

    #[\Override]
    protected $fillable = [
        'app_id',
        'enabled',
        'public_hosts',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'public_hosts' => 'array',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
