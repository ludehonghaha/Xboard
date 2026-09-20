<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_nobrand_user_binding', function (Blueprint $table) {
            $table->text('credential_payload')->nullable()->after('runtime_meta');
        });
    }

    public function down(): void
    {
        Schema::table('v2_nobrand_user_binding', function (Blueprint $table) {
            $table->dropColumn('credential_payload');
        });
    }
};
