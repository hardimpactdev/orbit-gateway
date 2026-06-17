<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TABLE database_connection_targets (
                    id integer primary key autoincrement not null,
                    database_connection_id integer not null,
                    app_id integer,
                    workspace_id integer,
                    env_prefix varchar not null,
                    created_at datetime,
                    updated_at datetime,
                    constraint database_connection_targets_database_connection_id_foreign
                        foreign key (database_connection_id) references database_connections (id) on delete cascade,
                    constraint database_connection_targets_app_id_foreign
                        foreign key (app_id) references apps (id) on delete cascade,
                    constraint database_connection_targets_workspace_id_foreign
                        foreign key (workspace_id) references workspaces (id) on delete cascade,
                    constraint database_connection_targets_owner_check
                        check ((app_id is null) <> (workspace_id is null))
                )
            SQL);

            DB::statement('create unique index database_connection_targets_app_id_env_prefix_unique on database_connection_targets (app_id, env_prefix)');
            DB::statement('create unique index database_connection_targets_workspace_id_env_prefix_unique on database_connection_targets (workspace_id, env_prefix)');

            return;
        }

        Schema::create('database_connection_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('database_connection_id')->constrained('database_connections')->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->string('env_prefix');
            $table->timestamps();

            $table->unique(['app_id', 'env_prefix']);
            $table->unique(['workspace_id', 'env_prefix']);
        });

        DB::statement(
            'alter table database_connection_targets add constraint database_connection_targets_owner_check check ((app_id is null) <> (workspace_id is null))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('database_connection_targets');
    }
};
