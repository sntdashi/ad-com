<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_providers')) {
            Schema::create('ai_providers', function (Blueprint $table): void {
                $table->id();
                $table->string('driver', 32)->index();
                $table->string('name', 120);
                $table->string('base_url', 255)->nullable();
                $table->text('api_token')->nullable();
                $table->string('model_id', 120)->nullable();
                $table->string('model_display_name', 120)->nullable();
                $table->json('discovered_models')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false)->index();
                $table->string('connection_status', 24)->default('untested');
                $table->text('last_error')->nullable();
                $table->timestamp('last_validated_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
