<?php

declare(strict_types=1);

use App\Services\Apps\AppProxyRouteRuntimeUpstreamBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->string('runtime_kind')->default('php')->after('php_version');
        });

        // Backfill legacy app proxy route configs so they carry a
        // Docker-first `runtime_upstream` derived from the app identity.
        // See AppProxyRouteRuntimeUpstreamBackfill for the contract.
        (new AppProxyRouteRuntimeUpstreamBackfill)->run();
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->dropColumn('runtime_kind');
        });
    }
};
