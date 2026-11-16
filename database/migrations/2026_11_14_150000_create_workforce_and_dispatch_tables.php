<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('availability_status')->default('available')->after('show_phone_publicly');
            $table->string('availability_note')->nullable()->after('availability_status');
            $table->timestamp('availability_updated_at')->nullable()->after('availability_note');

            $table->index(['role', 'availability_status']);
        });

        Schema::create('technician_availability_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_status');
            $table->string('new_status');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('technician_work_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('service_order_id', 26);
            $table->foreignId('technician_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('in_progress');
            $table->timestamp('started_at');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('service_order_id')->references('id')->on('service_orders')->cascadeOnDelete();
            $table->index(['service_order_id', 'status']);
            $table->index(['technician_id', 'status']);
        });

        Schema::create('order_work_evidences', function (Blueprint $table): void {
            $table->id();
            $table->char('service_order_id', 26);
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('stage'); // before, after, completion
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->text('caption')->nullable();
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmation_note')->nullable();
            $table->timestamps();

            $table->foreign('service_order_id')->references('id')->on('service_orders')->cascadeOnDelete();
            $table->index(['service_order_id', 'stage']);
        });

        // Backfill busy status for technicians who currently have active in-progress/on_the_way orders
        $busyTechIds = DB::table('order_assignments')
            ->join('service_orders', 'service_orders.id', '=', 'order_assignments.service_order_id')
            ->where('order_assignments.assignment_role', 'technician')
            ->whereNull('order_assignments.released_at')
            ->whereIn('service_orders.status', ['on_the_way', 'in_progress'])
            ->pluck('order_assignments.user_id')
            ->merge(
                DB::table('service_orders')
                    ->whereNotNull('assigned_technician_id')
                    ->whereIn('status', ['on_the_way', 'in_progress'])
                    ->pluck('assigned_technician_id'),
            )
            ->unique()
            ->all();

        if ($busyTechIds !== []) {
            DB::table('users')
                ->whereIn('id', $busyTechIds)
                ->where('role', 'technician')
                ->update([
                    'availability_status' => 'busy',
                    'availability_updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_work_evidences');
        Schema::dropIfExists('technician_work_sessions');
        Schema::dropIfExists('technician_availability_logs');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'availability_status']);
            $table->dropColumn([
                'availability_status',
                'availability_note',
                'availability_updated_at',
            ]);
        });
    }
};
