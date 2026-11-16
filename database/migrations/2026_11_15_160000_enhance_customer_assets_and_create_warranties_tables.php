<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_assets', function (Blueprint $table): void {
            $table->string('device_code', 24)->nullable()->unique()->after('origin_order_id');
            $table->string('serial_number_hash', 64)->nullable()->index()->after('serial_masked');
            $table->string('status', 24)->default('active')->index()->after('location');
            $table->text('notes')->nullable()->after('status');
            $table->timestamp('registered_at')->nullable()->after('last_serviced_at');

            $table->unique(['customer_id', 'serial_number_hash'], 'customer_assets_customer_serial_unique');
        });

        Schema::table('asset_service_records', function (Blueprint $table): void {
            $table->json('device_snapshot')->nullable()->after('service_order_id');
        });

        Schema::table('service_orders', function (Blueprint $table): void {
            $table->foreignUlid('customer_asset_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customer_assets')
                ->nullOnDelete();
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->unsignedSmallInteger('warranty_days')->nullable()->after('reorder_level');
            $table->text('warranty_terms')->nullable()->after('warranty_days');
        });

        Schema::create('warranties', function (Blueprint $table): void {
            $table->id();
            $table->string('warranty_number', 32)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('customer_asset_id')->nullable()->constrained('customer_assets')->nullOnDelete();
            $table->foreignUlid('service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignId('service_order_spare_part_id')
                ->nullable()
                ->constrained('service_order_spare_parts')
                ->nullOnDelete();
            $table->string('scope_type', 24)->index(); // service, spare_part
            $table->string('item_label', 160);
            $table->string('reference_number', 64)->nullable();
            $table->unsignedSmallInteger('duration_days');
            $table->text('coverage_description')->nullable();
            $table->string('status', 24)->default('active')->index(); // pending, active, expired, void
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_notified_at')->nullable();
            $table->timestamp('expiring_notified_at')->nullable();
            $table->timestamp('expired_notified_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['service_order_id', 'scope_type']);
        });

        Schema::create('warranty_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('claim_number', 32)->unique();
            $table->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('customer_asset_id')->nullable()->constrained('customer_assets')->nullOnDelete();
            $table->foreignUlid('original_service_order_id')->constrained('service_orders')->cascadeOnDelete();
            $table->foreignUlid('claim_service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('submitted')->index(); // submitted, under_review, approved, rejected, resolved
            $table->text('issue_description');
            $table->boolean('eligibility_checked')->default(false);
            $table->text('eligibility_notes')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['warranty_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('warranties');

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn(['warranty_days', 'warranty_terms']);
        });

        Schema::table('service_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_asset_id');
        });

        Schema::table('asset_service_records', function (Blueprint $table): void {
            $table->dropColumn(['device_snapshot']);
        });

        Schema::table('customer_assets', function (Blueprint $table): void {
            $table->dropUnique('customer_assets_customer_serial_unique');
            $table->dropColumn(['device_code', 'serial_number_hash', 'status', 'notes', 'registered_at']);
        });
    }
};
