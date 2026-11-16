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
        Schema::create('technician_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('quality_rating');
            $table->unsignedTinyInteger('communication_rating');
            $table->unsignedTinyInteger('punctuality_rating');
            $table->json('tags')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['service_order_id', 'technician_id']);
            $table->index(['technician_id', 'created_at']);
            $table->index(['technician_id', 'rating']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technician_reviews');
    }
};
