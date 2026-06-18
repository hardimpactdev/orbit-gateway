<?php

declare(strict_types=1);

use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('name');
            $table->string('driver');
            $table->json('driver_config');
            $table->json('runtime_requirements')->nullable();
            $table->string('latest_deployment_status')->nullable();
            $table->unsignedBigInteger('latest_deployment_run_id')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'name']);
            $table->index('driver');
        });

        DB::table('apps')
            ->orderBy('id')
            ->get()
            ->each(function (object $app): void {
                $name = is_string($app->environment ?? null) && trim($app->environment) !== ''
                    ? trim($app->environment)
                    : 'default';

                DB::table('app_instances')->insert([
                    'app_id' => $app->id,
                    'name' => $name,
                    'driver' => 'orbit',
                    'driver_config' => json_encode([
                        'type' => 'orbit_app_instance_driver_config',
                        'data' => [
                            'node_id' => $app->node_id,
                            'node' => DB::table('nodes')->where('id', $app->node_id)->value('name'),
                            'path' => $app->path,
                            'document_root' => $app->document_root,
                            'domain' => $app->domain,
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'runtime_requirements' => json_encode((new AppInstanceRuntimeRequirementsData)->toArray(), JSON_THROW_ON_ERROR),
                    'latest_deployment_status' => $app->latest_deployment_status ?? null,
                    'latest_deployment_run_id' => $app->latest_deployment_run_id ?? null,
                    'created_at' => $app->created_at,
                    'updated_at' => $app->updated_at,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_instances');
    }
};
