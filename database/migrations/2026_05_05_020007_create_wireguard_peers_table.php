<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wireguard_peers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('public_key');
            $table->text('private_key');
            $table->string('pre_shared_key')->nullable();
            $table->text('allowed_ips')->nullable();
            $table->timestamps();

            $table->unique('node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wireguard_peers');
    }
};
