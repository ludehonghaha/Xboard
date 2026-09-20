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
                ->after('is_active')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropIndex(['agent_driver']);
            $table->dropColumn('agent_driver');
        });
    }
};
