<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppWebSocketBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property bool $enabled
 * @property string $reverb_app_id
 * @property string $reverb_app_key
 * @property string $reverb_app_secret
 * @property list<string> $allowed_origins
 * @property list<string> $public_hosts
 * @property-read App $app
 */
class AppWebSocketBinding extends Model
{
    /** @use HasFactory<AppWebSocketBindingFactory> */
    use HasFactory;

    #[\Override]
    protected $table = 'app_websocket_bindings';

    #[\Override]
    protected $fillable = [
        'app_id',
        'enabled',
        'reverb_app_id',
        'reverb_app_key',
        'reverb_app_secret',
        'allowed_origins',
        'public_hosts',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'reverb_app_secret' => 'encrypted',
            'allowed_origins' => 'array',
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
