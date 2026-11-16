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
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('category');
            $table->unsignedBigInteger('selling_price')->nullable()->after('unit_cost');
            $table->string('supplier_name')->nullable()->after('storage_location');
            $table->string('supplier_contact', 64)->nullable()->after('supplier_name');
            $table->timestamp('low_stock_alerted_at')->nullable()->after('is_active');
        });

        Schema::create('service_order_spare_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('requested_quantity');
            $table->unsignedInteger('issued_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->string('status', 24)->default('requested')->index();
            $table->text('request_notes')->nullable();
            $table->text('issue_notes')->nullable();
            $table->text('return_notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['service_order_id', 'status']);
            $table->index(['inventory_item_id', 'status']);
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('spare_part_request_id')
                ->nullable()
                ->after('service_order_id')
                ->constrained('service_order_spare_parts')
                ->nullOnDelete();
            $table->foreignId('financial_transaction_id')
                ->nullable()
                ->after('actor_id')
                ->constrained('financial_transactions')
                ->nullOnDelete();
            $table->unsignedInteger('balance_before')->nullable()->after('quantity');
            $table->string('reference', 96)->nullable()->after('unit_cost');
            $table->string('supplier_name')->nullable()->after('reference');
            $table->text('adjustment_reason')->nullable()->after('supplier_name');

            $table->index(['type', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex(['type', 'occurred_at']);
            $table->dropConstrainedForeignId('spare_part_request_id');
            $table->dropConstrainedForeignId('financial_transaction_id');
            $table->dropColumn(['balance_before', 'reference', 'supplier_name', 'adjustment_reason']);
        });

        Schema::dropIfExists('service_order_spare_parts');

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn([
                'description',
                'selling_price',
                'supplier_name',
                'supplier_contact',
                'low_stock_alerted_at',
            ]);
        });
    }
};
