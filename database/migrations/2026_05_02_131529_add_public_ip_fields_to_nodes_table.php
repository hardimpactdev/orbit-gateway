<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('public_ipv4')->nullable()->after('gateway_endpoint');
            $table->string('public_ipv6')->nullable()->after('public_ipv4');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['public_ipv4', 'public_ipv6']);
        });
    }
};
