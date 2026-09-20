<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_nobrand_user_binding', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('user_id');
            $table->string('remote_user', 128);
            $table->string('instance_id', 64)->nullable();
            $table->string('display_host', 255)->nullable();
            $table->unsignedInteger('display_port');
            $table->string('transport', 16)->default('TCP');
            $table->boolean('enabled')->default(true);
            $table->json('runtime_meta')->nullable();
            $table->unsignedInteger('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'user_id'], 'nobrand_binding_server_user_unique');
            $table->index(['user_id', 'enabled']);
            $table->foreign('server_id')->references('id')->on('v2_server')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('v2_user')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_nobrand_user_binding');
    }
};
