<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_nobrand_policy_binding', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('user_id');
            $table->string('remote_user', 64);
            $table->string('quota_mode', 16)->default('calendar');
            $table->unsignedInteger('quota_days')->default(30);
            $table->boolean('sync_enabled')->default(true);
            $table->string('last_status', 16)->default('pending');
            $table->string('last_message', 1000)->nullable();
            $table->json('last_remote_state')->nullable();
            $table->unsignedInteger('last_applied_at')->nullable();
            $table->timestamps();

            $table->unique(['machine_id', 'user_id'], 'nobrand_policy_machine_user_unique');
            $table->unique(['machine_id', 'remote_user'], 'nobrand_policy_machine_remote_unique');
            $table->foreign('machine_id')->references('id')->on('v2_server_machine')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('v2_user')->cascadeOnDelete();
        });

        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->unsignedInteger('policy_last_seen_at')->nullable()->after('last_seen_at');
            $table->json('policy_status')->nullable()->after('policy_last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn(['policy_last_seen_at', 'policy_status']);
        });

        Schema::dropIfExists('v2_nobrand_policy_binding');
    }
};
