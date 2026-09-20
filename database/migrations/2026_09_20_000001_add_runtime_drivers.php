<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->string('agent_driver', 32)
                ->default('xboard-node')
                ->after('is_active');
            $table->json('agent_settings')
                ->nullable()
                ->after('agent_driver');
        });

        Schema::table('v2_server', function (Blueprint $table) {
            $table->string('runtime_driver', 32)
                ->default('native')
                ->after('machine_id');
            $table->json('runtime_driver_settings')
                ->nullable()
                ->after('runtime_driver');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn(['runtime_driver', 'runtime_driver_settings']);
        });

        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn(['agent_driver', 'agent_settings']);
        });
    }
};
