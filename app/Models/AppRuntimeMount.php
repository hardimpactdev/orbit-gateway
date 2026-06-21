<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $app_id
 * @property string $source
 * @property string $target
 * @property bool $read_only
 * @property-read App $app
 */
class AppRuntimeMount extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'app_id',
        'source',
        'target',
        'read_only',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
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
