<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('avatar_url')->nullable()->after('phone');
            $table->string('specialty')->nullable()->after('avatar_url');
            $table->text('bio')->nullable()->after('specialty');
            $table->boolean('show_phone_publicly')->default(false)->after('bio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'specialty', 'bio', 'show_phone_publicly']);
        });
    }
};
