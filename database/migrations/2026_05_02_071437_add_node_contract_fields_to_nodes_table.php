<?php

declare(strict_types=1);

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
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('environment')->nullable();
            $table->string('tld')->nullable();
            $table->string('platform')->nullable();
            $table->string('wireguard_address')->nullable();
            $table->string('gateway_endpoint')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn([
                'environment',
                'tld',
                'platform',
                'wireguard_address',
                'gateway_endpoint',
            ]);
        });
    }
};
