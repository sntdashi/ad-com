<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_otp_challenges', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('login_otp_challenges', function (Blueprint $table): void {
            if (! Schema::hasColumn('login_otp_challenges', 'challenge_id')) {
                $table->uuid('challenge_id')->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('login_otp_challenges', 'target_phone')) {
                $table->string('target_phone', 32)->nullable()->index()->after('purpose');
            }
            if (! Schema::hasColumn('login_otp_challenges', 'resend_count')) {
                $table->unsignedSmallInteger('resend_count')->default(0)->after('attempts');
            }
            if (! Schema::hasColumn('login_otp_challenges', 'resend_available_at')) {
                $table->timestamp('resend_available_at')->nullable()->after('expires_at');
            }
            if (! Schema::hasColumn('login_otp_challenges', 'status')) {
                $table->string('status', 24)->default('pending')->index()->after('resend_available_at');
            }
            if (! Schema::hasColumn('login_otp_challenges', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('login_otp_challenges', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->index()->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('login_otp_challenges', function (Blueprint $table): void {
            $columns = array_filter([
                'challenge_id',
                'target_phone',
                'resend_count',
                'resend_available_at',
                'status',
                'verified_at',
                'locked_until',
            ], fn (string $col): bool => Schema::hasColumn('login_otp_challenges', $col));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
