<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_nobrand_traffic_report', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('server_id');
            $table->string('report_id', 64)->unique();
            $table->json('readings');
            $table->unsignedInteger('observed_at');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('delta_u')->default(0);
            $table->unsignedBigInteger('delta_d')->default(0);
            $table->unsignedInteger('processed_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['machine_id', 'created_at']);
            $table->foreign('machine_id')->references('id')->on('v2_server_machine')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('v2_server')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_nobrand_traffic_report');
    }
};
