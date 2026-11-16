<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('customer')->index();
            $table->string('phone', 24)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('summary');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('starting_price')->nullable();
            $table->string('pricing_label');
            $table->string('service_mode')->default('onsite');
            $table->string('risk_level')->default('medium');
            $table->string('icon')->default('wrench');
            $table->unsignedSmallInteger('warranty_days')->default(7);
            $table->json('outcomes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('ticket_number', 24)->unique();
            $table->string('tracking_token_hash', 64)->unique();
            $table->string('access_code_hash', 64);
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name');
            $table->string('phone', 24);
            $table->string('email')->nullable();
            $table->text('address');
            $table->string('district')->nullable();
            $table->string('service_mode')->default('onsite');
            $table->text('complaint');
            $table->date('preferred_date')->nullable();
            $table->string('preferred_window')->nullable();
            $table->string('urgency')->default('normal');
            $table->string('risk_level')->default('unassessed');
            $table->string('status')->default('new')->index();
            $table->unsignedBigInteger('estimated_total')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'preferred_date']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('visibility')->default('customer');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['service_order_id', 'occurred_at']);
        });

        Schema::create('order_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('assignment_role')->default('technician');
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['service_order_id', 'user_id', 'assignment_role'], 'order_assignment_unique');
        });

        Schema::create('estimates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('total');
            $table->text('notes')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['service_order_id', 'version']);
        });

        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('estimate_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('status');
            $table->string('actor_name')->nullable();
            $table->text('notes')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('work_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility')->default('internal');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('minutes_spent')->nullable();
            $table->string('evidence_path')->nullable();
            $table->timestamp('logged_at');
            $table->timestamps();
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('item_key');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('competency_code')->nullable();
            $table->boolean('requires_evidence')->default(false);
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['service_id', 'item_key']);
        });

        Schema::create('order_checklist_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result')->default('pending');
            $table->text('notes')->nullable();
            $table->string('evidence_path')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['service_order_id', 'checklist_template_id'], 'order_checklist_unique');
        });

        Schema::create('customer_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('origin_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->string('name');
            $table->string('category');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_masked')->nullable();
            $table->string('location')->nullable();
            $table->string('passport_token_hash', 64)->nullable()->unique();
            $table->timestamp('last_serviced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('customer_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_order_id')->constrained()->cascadeOnDelete();
            $table->text('diagnosis')->nullable();
            $table->text('action_taken')->nullable();
            $table->text('test_result')->nullable();
            $table->text('recommendation')->nullable();
            $table->date('warranty_until')->nullable();
            $table->timestamps();
            $table->unique(['customer_asset_id', 'service_order_id']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('service_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('public_consent')->default(false);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('auditable_type');
            $table->string('auditable_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('asset_service_records');
        Schema::dropIfExists('customer_assets');
        Schema::dropIfExists('order_checklist_results');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('work_logs');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
        Schema::dropIfExists('order_assignments');
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('services');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'is_active']);
        });
    }
};
