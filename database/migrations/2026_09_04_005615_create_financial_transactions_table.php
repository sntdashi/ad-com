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
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('service_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16);
            $table->string('category', 64);
            $table->unsignedBigInteger('amount');
            $table->string('payment_method', 32)->default('bank_transfer');
            $table->string('status', 24)->default('pending');
            $table->string('reference', 96)->nullable();
            $table->text('description')->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index(['service_order_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
