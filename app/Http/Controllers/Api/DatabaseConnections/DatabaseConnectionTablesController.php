<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionTablesController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->schemaOperation($request, 'tables');
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /database-connections/tables';
    }
}
