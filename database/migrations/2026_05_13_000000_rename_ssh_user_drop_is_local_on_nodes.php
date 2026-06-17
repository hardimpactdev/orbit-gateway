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
        if (Schema::hasColumn('nodes', 'ssh_user') && ! Schema::hasColumn('nodes', 'user')) {
            Schema::table('nodes', function (Blueprint $table): void {
                $table->renameColumn('ssh_user', 'user');
            });
        }

        if (Schema::hasColumn('nodes', 'ssh_user') && Schema::hasColumn('nodes', 'user')) {
            DB::table('nodes')
                ->whereNull('user')
                ->update(['user' => DB::raw('ssh_user')]);

            Schema::table('nodes', function (Blueprint $table): void {
                $table->dropColumn('ssh_user');
            });
        }

        if (Schema::hasColumn('nodes', 'is_local')) {
            Schema::table('nodes', function (Blueprint $table): void {
                $table->dropColumn('is_local');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('nodes', 'ssh_user')) {
            Schema::table('nodes', function (Blueprint $table): void {
                $table->string('ssh_user')->nullable()->after('host');
            });

            DB::table('nodes')
                ->whereNull('ssh_user')
                ->update(['ssh_user' => DB::raw('user')]);
        }

        if (! Schema::hasColumn('nodes', 'is_local')) {
            Schema::table('nodes', function (Blueprint $table): void {
                $table->boolean('is_local')->default(false)->after('status');
            });
        }
    }
};
