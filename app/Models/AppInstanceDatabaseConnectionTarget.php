<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $database_connection_id
 * @property int $app_instance_id
 * @property string $env_prefix
 * @property-read DatabaseConnection $connection
 * @property-read AppInstance $instance
 */
class AppInstanceDatabaseConnectionTarget extends Model
{
    #[\Override]
    protected $fillable = [
        'database_connection_id',
        'app_instance_id',
        'env_prefix',
    ];

    /**
     * @return BelongsTo<DatabaseConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class, 'database_connection_id');
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
    }
}
