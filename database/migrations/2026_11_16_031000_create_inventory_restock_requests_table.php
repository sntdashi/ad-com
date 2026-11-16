<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_restock_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 32)->unique();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('requested_quantity');
            $table->unsignedBigInteger('estimated_total_cost')->default(0);
            $table->string('priority', 20)->default('normal');
            $table->string('status', 32)->default('pending');
            $table->string('supplier_name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['inventory_item_id', 'status'], 'inventory_restock_item_status_idx');
            $table->index(['status', 'created_at'], 'inventory_restock_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_restock_requests');
    }
};
