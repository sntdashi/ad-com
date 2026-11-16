<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_action_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action_name', 64);
            $table->string('status', 32)->default('requested');
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->json('preview_summary')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('result_reference', 120)->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'assistant_proposals_user_status_idx');
            $table->index(['action_name', 'status'], 'assistant_proposals_action_status_idx');
            $table->index(['expires_at'], 'assistant_proposals_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_action_proposals');
    }
};
