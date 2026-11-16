<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table): void {
            $table->timestamp('first_responded_at')->nullable()->after('approved_at');
            $table->timestamp('response_due_at')->nullable()->after('first_responded_at');
            $table->timestamp('resolution_due_at')->nullable()->after('response_due_at');
            $table->timestamp('sla_paused_at')->nullable()->after('resolution_due_at');
            $table->unsignedInteger('sla_paused_seconds')->default(0)->after('sla_paused_at');
            $table->string('sla_pause_reason')->nullable()->after('sla_paused_seconds');
            $table->timestamp('sla_response_notified_at')->nullable()->after('sla_pause_reason');
            $table->timestamp('sla_resolution_notified_at')->nullable()->after('sla_response_notified_at');
            $table->timestamp('sla_breach_notified_at')->nullable()->after('sla_resolution_notified_at');

            $table->index('response_due_at');
            $table->index('resolution_due_at');
            $table->index('sla_paused_at');
        });

        $orders = DB::table('service_orders')->get([
            'id',
            'urgency',
            'risk_level',
            'status',
            'created_at',
            'updated_at',
            'completed_at',
        ]);

        foreach ($orders as $order) {
            $createdAt = $order->created_at ? Carbon::parse((string) $order->created_at) : now();
            $isHighPriority = in_array($order->urgency, ['soon', 'high', 'urgent'], true) || $order->risk_level === 'high';
            $responseMinutes = $order->risk_level === 'high' ? 30 : ($isHighPriority ? 60 : 120);
            $resolutionMinutes = $order->risk_level === 'high' ? 240 : ($isHighPriority ? 480 : 1440);

            $firstRespondedAt = null;
            if ($order->status !== 'new') {
                $firstRespondedAt = $order->updated_at
                    ? Carbon::parse((string) $order->updated_at)
                    : $createdAt->copy()->addMinutes(min(15, $responseMinutes));
            }

            $pausedAt = in_array($order->status, ['needs_info', 'waiting_approval', 'waiting_payment'], true)
                ? ($order->updated_at ? Carbon::parse((string) $order->updated_at) : now())
                : null;
            $pauseReason = $pausedAt !== null ? 'Menunggu konfirmasi / respons pelanggan' : null;

            DB::table('service_orders')
                ->where('id', $order->id)
                ->update([
                    'first_responded_at' => $firstRespondedAt,
                    'response_due_at' => $createdAt->copy()->addMinutes($responseMinutes),
                    'resolution_due_at' => $createdAt->copy()->addMinutes($resolutionMinutes),
                    'sla_paused_at' => $pausedAt,
                    'sla_pause_reason' => $pauseReason,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table): void {
            $table->dropIndex(['response_due_at']);
            $table->dropIndex(['resolution_due_at']);
            $table->dropIndex(['sla_paused_at']);
            $table->dropColumn([
                'first_responded_at',
                'response_due_at',
                'resolution_due_at',
                'sla_paused_at',
                'sla_paused_seconds',
                'sla_pause_reason',
                'sla_response_notified_at',
                'sla_resolution_notified_at',
                'sla_breach_notified_at',
            ]);
        });
    }
};
