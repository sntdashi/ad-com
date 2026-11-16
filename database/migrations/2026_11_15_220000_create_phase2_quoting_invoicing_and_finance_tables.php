<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('estimates')) {
            Schema::table('estimates', function (Blueprint $table): void {
                if (! Schema::hasColumn('estimates', 'quotation_number')) {
                    $table->string('quotation_number', 40)->nullable()->after('id');
                }
                if (! Schema::hasColumn('estimates', 'customer_id')) {
                    $table->foreignId('customer_id')->nullable()->after('service_order_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('estimates', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('estimates', 'labor_total')) {
                    $table->unsignedBigInteger('labor_total')->default(0)->after('status');
                }
                if (! Schema::hasColumn('estimates', 'spare_parts_total')) {
                    $table->unsignedBigInteger('spare_parts_total')->default(0)->after('labor_total');
                }
                if (! Schema::hasColumn('estimates', 'operational_total')) {
                    $table->unsignedBigInteger('operational_total')->default(0)->after('spare_parts_total');
                }
                if (! Schema::hasColumn('estimates', 'subtotal')) {
                    $table->unsignedBigInteger('subtotal')->default(0)->after('operational_total');
                }
                if (! Schema::hasColumn('estimates', 'discount_amount')) {
                    $table->unsignedBigInteger('discount_amount')->default(0)->after('subtotal');
                }
                if (! Schema::hasColumn('estimates', 'tax_rate')) {
                    $table->decimal('tax_rate', 5, 2)->default(0)->after('discount_amount');
                }
                if (! Schema::hasColumn('estimates', 'tax_amount')) {
                    $table->unsignedBigInteger('tax_amount')->default(0)->after('tax_rate');
                }
                if (! Schema::hasColumn('estimates', 'approved_by')) {
                    $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('estimates', 'rejected_at')) {
                    $table->timestamp('rejected_at')->nullable()->after('approved_by');
                }
                if (! Schema::hasColumn('estimates', 'rejected_by')) {
                    $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('estimates', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('rejected_by');
                }
                if (! Schema::hasColumn('estimates', 'converted_at')) {
                    $table->timestamp('converted_at')->nullable()->after('rejection_reason');
                }
                if (! Schema::hasColumn('estimates', 'approved_snapshot')) {
                    $table->json('approved_snapshot')->nullable()->after('converted_at');
                }
            });

            // Safely backfill existing estimates without altering existing totals or statuses
            $existingEstimates = DB::table('estimates')
                ->leftJoin('service_orders', 'estimates.service_order_id', '=', 'service_orders.id')
                ->select([
                    'estimates.id',
                    'estimates.version',
                    'estimates.total',
                    'estimates.quotation_number',
                    'estimates.customer_id',
                    'service_orders.customer_id as order_customer_id',
                ])
                ->orderBy('estimates.created_at')
                ->get();

            foreach ($existingEstimates as $index => $row) {
                $updates = [];
                if ($row->quotation_number === null || $row->quotation_number === '') {
                    $seq = str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
                    $updates['quotation_number'] = 'QUO-LEG-'.$seq.'-'.substr(strtoupper((string) $row->id), -4);
                }
                if ($row->customer_id === null && $row->order_customer_id !== null) {
                    $updates['customer_id'] = $row->order_customer_id;
                }
                $updates['subtotal'] = (int) $row->total;
                $updates['labor_total'] = (int) $row->total;

                DB::table('estimates')
                    ->where('id', $row->id)
                    ->update($updates);
            }

            Schema::table('estimates', function (Blueprint $table): void {
                $table->unique('quotation_number', 'estimates_quotation_number_unique');
                $table->index(['customer_id', 'status'], 'estimates_customer_status_idx');
            });
        }

        if (Schema::hasTable('estimate_items')) {
            Schema::table('estimate_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('estimate_items', 'item_type')) {
                    $table->string('item_type', 24)->default('labor')->after('estimate_id');
                }
                if (! Schema::hasColumn('estimate_items', 'inventory_item_id')) {
                    $table->foreignId('inventory_item_id')->nullable()->after('item_type')->constrained('inventory_items')->nullOnDelete();
                }
                if (! Schema::hasColumn('estimate_items', 'service_order_spare_part_id')) {
                    $table->foreignId('service_order_spare_part_id')->nullable()->after('inventory_item_id')->constrained('service_order_spare_parts')->nullOnDelete();
                }
                if (! Schema::hasColumn('estimate_items', 'estimated_unit_cost')) {
                    $table->unsignedBigInteger('estimated_unit_cost')->default(0)->after('unit_price');
                }
            });
        }

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('invoice_number', 40)->unique();
                $table->foreignUlid('service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
                $table->foreignUlid('estimate_id')->nullable()->unique()->constrained('estimates')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('customer_name');
                $table->string('customer_email')->nullable();
                $table->string('customer_phone', 32)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 24)->default('draft')->index();
                $table->date('issue_date')->nullable();
                $table->date('due_date')->nullable()->index();
                $table->unsignedBigInteger('subtotal')->default(0);
                $table->unsignedBigInteger('discount_amount')->default(0);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->unsignedBigInteger('tax_amount')->default(0);
                $table->unsignedBigInteger('total_amount')->default(0);
                $table->unsignedBigInteger('paid_amount')->default(0);
                $table->json('issued_snapshot')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('issued_at')->nullable()->index();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'status'], 'invoices_customer_status_idx');
                $table->index(['service_order_id', 'status'], 'invoices_order_status_idx');
            });
        }

        if (! Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('item_type', 24)->default('labor');
                $table->string('label');
                $table->text('description')->nullable();
                $table->decimal('quantity', 10, 2)->default(1);
                $table->unsignedBigInteger('unit_price')->default(0);
                $table->unsignedBigInteger('unit_cost')->default(0);
                $table->unsignedBigInteger('line_total')->default(0);
                $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
                $table->foreignId('service_order_spare_part_id')->nullable()->constrained('service_order_spare_parts')->nullOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('financial_transactions')) {
            Schema::table('financial_transactions', function (Blueprint $table): void {
                if (! Schema::hasColumn('financial_transactions', 'invoice_id')) {
                    $table->foreignUlid('invoice_id')->nullable()->after('service_order_id')->constrained('invoices')->nullOnDelete();
                }
                if (! Schema::hasColumn('financial_transactions', 'idempotency_key')) {
                    $table->string('idempotency_key', 96)->nullable()->after('reference');
                }
                if (! Schema::hasColumn('financial_transactions', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('verified_at');
                }
                if (! Schema::hasColumn('financial_transactions', 'rejected_by')) {
                    $table->foreignId('rejected_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('financial_transactions', 'rejected_at')) {
                    $table->timestamp('rejected_at')->nullable()->after('rejected_by');
                }
            });

            Schema::table('financial_transactions', function (Blueprint $table): void {
                $table->unique('idempotency_key', 'ft_idempotency_key_unique');
                $table->index(['invoice_id', 'status'], 'ft_invoice_status_idx');
            });
        }
    }

    public function down(): void
    {
        // Forward-only protective down(): only drops newly added Phase 2 structures if explicitly rolled back
        if (Schema::hasTable('financial_transactions')) {
            Schema::table('financial_transactions', function (Blueprint $table): void {
                if (Schema::hasColumn('financial_transactions', 'rejected_by')) {
                    $table->dropConstrainedForeignId('rejected_by');
                }
                if (Schema::hasColumn('financial_transactions', 'invoice_id')) {
                    $table->dropIndex('ft_invoice_status_idx');
                    $table->dropConstrainedForeignId('invoice_id');
                }
                if (Schema::hasColumn('financial_transactions', 'idempotency_key')) {
                    $table->dropUnique('ft_idempotency_key_unique');
                    $table->dropColumn('idempotency_key');
                }
                $dropCols = array_filter([
                    Schema::hasColumn('financial_transactions', 'rejection_reason') ? 'rejection_reason' : null,
                    Schema::hasColumn('financial_transactions', 'rejected_at') ? 'rejected_at' : null,
                ]);
                if ($dropCols !== []) {
                    $table->dropColumn($dropCols);
                }
            });
        }

        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');

        if (Schema::hasTable('estimate_items')) {
            Schema::table('estimate_items', function (Blueprint $table): void {
                if (Schema::hasColumn('estimate_items', 'service_order_spare_part_id')) {
                    $table->dropConstrainedForeignId('service_order_spare_part_id');
                }
                if (Schema::hasColumn('estimate_items', 'inventory_item_id')) {
                    $table->dropConstrainedForeignId('inventory_item_id');
                }
                $dropCols = array_filter([
                    Schema::hasColumn('estimate_items', 'item_type') ? 'item_type' : null,
                    Schema::hasColumn('estimate_items', 'estimated_unit_cost') ? 'estimated_unit_cost' : null,
                ]);
                if ($dropCols !== []) {
                    $table->dropColumn($dropCols);
                }
            });
        }

        if (Schema::hasTable('estimates')) {
            Schema::table('estimates', function (Blueprint $table): void {
                $table->dropUnique('estimates_quotation_number_unique');
                $table->dropIndex('estimates_customer_status_idx');
                foreach (['customer_id', 'created_by', 'approved_by', 'rejected_by'] as $fk) {
                    if (Schema::hasColumn('estimates', $fk)) {
                        $table->dropConstrainedForeignId($fk);
                    }
                }
                $dropCols = array_filter([
                    Schema::hasColumn('estimates', 'quotation_number') ? 'quotation_number' : null,
                    Schema::hasColumn('estimates', 'labor_total') ? 'labor_total' : null,
                    Schema::hasColumn('estimates', 'spare_parts_total') ? 'spare_parts_total' : null,
                    Schema::hasColumn('estimates', 'operational_total') ? 'operational_total' : null,
                    Schema::hasColumn('estimates', 'subtotal') ? 'subtotal' : null,
                    Schema::hasColumn('estimates', 'discount_amount') ? 'discount_amount' : null,
                    Schema::hasColumn('estimates', 'tax_rate') ? 'tax_rate' : null,
                    Schema::hasColumn('estimates', 'tax_amount') ? 'tax_amount' : null,
                    Schema::hasColumn('estimates', 'rejected_at') ? 'rejected_at' : null,
                    Schema::hasColumn('estimates', 'rejection_reason') ? 'rejection_reason' : null,
                    Schema::hasColumn('estimates', 'converted_at') ? 'converted_at' : null,
                    Schema::hasColumn('estimates', 'approved_snapshot') ? 'approved_snapshot' : null,
                ]);
                if ($dropCols !== []) {
                    $table->dropColumn($dropCols);
                }
            });
        }
    }
};
