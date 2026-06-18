<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('host_key_type')->nullable();
            $table->string('host_key_fingerprint')->nullable();
            $table->text('host_key_public')->nullable();
            $table->timestamp('host_key_pinned_at')->nullable();
            $table->string('host_key_pin_mode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn([
                'host_key_type',
                'host_key_fingerprint',
                'host_key_public',
                'host_key_pinned_at',
                'host_key_pin_mode',
            ]);
        });
    }
};
