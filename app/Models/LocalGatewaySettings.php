<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $gateway_url
 * @property string|null $gateway_wg_ip
 * @property string|null $ca_sha256
 * @property string|null $ca_pem_path
 * @property Carbon|null $trusted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LocalGatewaySettings extends Model
{
    #[\Override]
    protected $fillable = [
        'gateway_url',
        'gateway_wg_ip',
        'ca_sha256',
        'ca_pem_path',
        'trusted_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'trusted_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        $record = self::query()->first();

        if ($record === null) {
            $record = self::query()->create([]);
        }

        return $record;
    }
}
