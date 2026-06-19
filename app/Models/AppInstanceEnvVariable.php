<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_instance_id
 * @property string $key
 * @property string|null $value
 * @property bool $secret
 * @property-read AppInstance $instance
 */
class AppInstanceEnvVariable extends Model
{
    #[\Override]
    protected $fillable = [
        'app_instance_id',
        'key',
        'value',
        'secret',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'secret' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
    }
}
