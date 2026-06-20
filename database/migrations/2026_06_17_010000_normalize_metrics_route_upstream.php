<?php

declare(strict_types=1);

use App\Services\Metrics\MetricsRouteUpstreamBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new MetricsRouteUpstreamBackfill)->run();
    }

    public function down(): void
    {
        //
    }
};
