<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_settings', function (Blueprint $table): void {
            $table->boolean('is_enabled')->default(true)->after('api_key');
            $table->string('base_url', 255)->nullable()->after('is_enabled');
            $table->unsignedSmallInteger('max_output_tokens')->default(450)->after('base_url');
            $table->text('custom_instructions')->nullable()->after('max_output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_settings', function (Blueprint $table): void {
            $table->dropColumn(['is_enabled', 'base_url', 'max_output_tokens', 'custom_instructions']);
        });
    }
};
