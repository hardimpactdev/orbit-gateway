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
        Schema::create('local_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_url')->nullable();
            $table->string('gateway_wg_ip')->nullable();
            $table->string('ca_sha256')->nullable();
            $table->string('ca_pem_path')->nullable();
            $table->timestamp('trusted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('local_gateway_settings');
    }
};
