<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->string('address_family')->default('both');
            $table->string('interface')->nullable();
            $table->string('owner')->default('user');
            $table->boolean('protected')->default(false);

            $table->index(['node_id', 'owner', 'protected']);
            $table->index(['node_id', 'address_family', 'interface']);
        });
    }

    public function down(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->dropIndex(['node_id', 'owner', 'protected']);
            $table->dropIndex(['node_id', 'address_family', 'interface']);
            $table->dropColumn([
                'address_family',
                'interface',
                'owner',
                'protected',
            ]);
        });
    }
};
