<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'category')) {
                $table->string('category', 32)->default('orders')->after('type')->index();
            }
            if (! Schema::hasColumn('notifications', 'priority')) {
                $table->string('priority', 16)->default('normal')->after('category')->index();
            }
            if (! Schema::hasColumn('notifications', 'dedupe_key')) {
                $table->string('dedupe_key', 120)->nullable()->after('priority')->index();
            }
            if (! Schema::hasColumn('notifications', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('read_at')->index();
            }
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            if (! Schema::hasColumn('notification_deliveries', 'dedupe_key')) {
                $table->string('dedupe_key', 120)->nullable()->after('event_key')->index();
            }
            if (! Schema::hasColumn('notification_deliveries', 'retried_by')) {
                $table->foreignId('retried_by')->nullable()->after('sent_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('notification_deliveries', 'retried_at')) {
                $table->timestamp('retried_at')->nullable()->after('retried_by');
            }
        });

        if (! Schema::hasTable('user_notification_preferences')) {
            Schema::create('user_notification_preferences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('category', 32);
                $table->boolean('in_app_enabled')->default(true);
                $table->boolean('whatsapp_enabled')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'category']);
            });
        }

        // Backfill category and priority from existing JSON payloads safely
        DB::table('notifications')->select(['id', 'data'])->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                $payload = json_decode((string) $row->data, true);
                if (! is_array($payload)) {
                    continue;
                }

                $eventKey = (string) ($payload['event_key'] ?? '');
                $category = match (true) {
                    str_starts_with($eventKey, 'sla.') => 'sla',
                    str_starts_with($eventKey, 'work.') => 'workforce',
                    str_starts_with($eventKey, 'inventory.') => 'inventory',
                    str_starts_with($eventKey, 'warranty.') => 'warranty',
                    str_starts_with($eventKey, 'finance.') => 'finance',
                    str_starts_with($eventKey, 'security.') => 'security',
                    default => 'orders',
                };
                $priority = match ($eventKey) {
                    'sla.breached', 'inventory.low_stock', 'warranty.claim_submitted' => 'high',
                    default => 'normal',
                };

                DB::table('notifications')
                    ->where('id', $row->id)
                    ->update([
                        'category' => $category,
                        'priority' => $priority,
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            if (Schema::hasColumn('notification_deliveries', 'retried_by')) {
                $table->dropConstrainedForeignId('retried_by');
            }
            $columns = array_filter([
                Schema::hasColumn('notification_deliveries', 'dedupe_key') ? 'dedupe_key' : null,
                Schema::hasColumn('notification_deliveries', 'retried_at') ? 'retried_at' : null,
            ]);
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('notifications', 'category') ? 'category' : null,
                Schema::hasColumn('notifications', 'priority') ? 'priority' : null,
                Schema::hasColumn('notifications', 'dedupe_key') ? 'dedupe_key' : null,
                Schema::hasColumn('notifications', 'archived_at') ? 'archived_at' : null,
            ]);
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
