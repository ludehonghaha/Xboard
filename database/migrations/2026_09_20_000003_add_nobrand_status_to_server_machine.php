<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->json('nobrand_status')->nullable()->after('agent_settings');
            $table->unsignedInteger('nobrand_last_seen_at')->nullable()->after('nobrand_status');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn(['nobrand_status', 'nobrand_last_seen_at']);
        });
    }
};
