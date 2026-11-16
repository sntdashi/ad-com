<?php

namespace App\Actions\Orders;

use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sla\SlaService;
use App\Services\Workforce\WorkforceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateOrderStatus
{
    public function handle(ServiceOrder $order, User $actor, string $status, ?string $note, ?string $ipAddress): ServiceOrder
    {
        $sla = app(SlaService::class);
        $workforce = app(WorkforceService::class);

        $updatedOrder = DB::transaction(function () use ($order, $actor, $status, $note, $ipAddress, $sla, $workforce): ServiceOrder {
            $lockedOrder = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);
            Gate::forUser($actor)->authorize('update', $lockedOrder);
            if (! in_array($status, self::allowedTransitions($actor, $lockedOrder), true)) {
                throw ValidationException::withMessages(['status' => 'Perubahan status ini tidak tersedia. Muat ulang tiket untuk melihat progres terbaru.']);
            }
            $before = ['status' => $lockedOrder->status];

            if ($actor->role !== 'customer') {
                $sla->recordFirstResponse($lockedOrder);
            }

            if ($lockedOrder->sla_paused_at !== null && ! in_array($status, SlaService::PAUSED_STATUSES, true)) {
                $trigger = in_array($status, SlaService::TERMINAL_STATUSES, true) ? 'completed' : 'status_resumed';
                $sla->resume($lockedOrder, $actor, $ipAddress, $trigger);
            }

            $updates = ['status' => $status];
            if ($status === 'completed') {
                $updates['completed_at'] = now();
            }
            $lockedOrder->update($updates);

            if (in_array($status, SlaService::PAUSED_STATUSES, true) && $lockedOrder->sla_paused_at === null) {
                $sla->pause($lockedOrder, $note ?: 'Menunggu respons atau persetujuan pelanggan', $actor, $ipAddress);
            }

            $techIds = $lockedOrder->activeTechnicianAssignments()
                ->pluck('user_id')
                ->when($lockedOrder->assigned_technician_id !== null, fn ($ids) => $ids->push($lockedOrder->assigned_technician_id))
                ->unique()
                ->all();

            if ($techIds !== []) {
                foreach (User::query()->whereKey($techIds)->get() as $tech) {
                    $workforce->syncAvailabilityWithActiveWork($tech, $actor);
                }
            }

            $lockedOrder->statusEvents()->create([
                'actor_id' => $actor->id,
                'status' => $status,
                'title' => self::statusLabel($status),
                'description' => $note ?: 'Status pekerjaan diperbarui oleh tim AD Com.',
                'visibility' => 'customer',
                'occurred_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'service_order.status_updated',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $lockedOrder->id,
                'before' => json_encode($before),
                'after' => json_encode(['status' => $status]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $lockedOrder;
        });

        if ($updatedOrder->customer !== null) {
            $statusLabel = self::statusLabel($status);
            app(NotificationDispatcher::class)->notifyUsers(
                [$updatedOrder->customer],
                'order.status_changed',
                "Status tiket {$updatedOrder->ticket_number}: {$statusLabel}",
                $note ?: 'Status pekerjaan tiket Anda telah diperbarui.',
                route('orders.show', $updatedOrder),
                ['ticket_number' => $updatedOrder->ticket_number, 'status' => $status],
            );
        }

        return $updatedOrder;
    }

    /** @return list<string> */
    public static function allowedTransitions(User $user, ServiceOrder $order): array
    {
        if ($user->role === 'technician') {
            return match ($order->status) {
                'scheduled' => ['on_the_way'],
                'on_the_way' => ['in_progress'],
                'in_progress' => ['verification'],
                default => [],
            };
        }
        if (! in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return [];
        }

        return match ($order->status) {
            'new' => ['needs_info', 'triaged', 'cancelled'],
            'needs_info' => ['triaged', 'cancelled'],
            'triaged' => ['waiting_estimate', 'waiting_approval', 'scheduled'],
            'waiting_estimate' => ['waiting_approval'],
            'waiting_approval' => ['scheduled', 'cancelled'],
            'scheduled' => ['on_the_way', 'in_progress', 'cancelled'],
            'on_the_way' => ['in_progress'],
            'in_progress' => ['verification'],
            'verification' => ['in_progress', 'waiting_payment', 'completed'],
            'waiting_payment' => ['completed'],
            'completed' => ['warranty', 'reopened', 'closed'],
            'warranty' => ['reopened', 'closed'],
            'reopened' => ['in_progress', 'waiting_estimate'],
            default => [],
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'new' => 'Permintaan baru',
            'needs_info' => 'Perlu info tambahan',
            'triaged' => 'Triase selesai',
            'waiting_estimate' => 'Menunggu estimasi',
            'waiting_approval' => 'Menunggu persetujuan',
            'scheduled' => 'Jadwal dikonfirmasi',
            'on_the_way' => 'Tim menuju lokasi',
            'in_progress' => 'Pengerjaan dimulai',
            'verification' => 'Menunggu verifikasi',
            'waiting_payment' => 'Menunggu pembayaran',
            'completed' => 'Pekerjaan selesai',
            'warranty' => 'Masa garansi aktif',
            'closed' => 'Tiket ditutup',
            'reopened' => 'Tiket dibuka kembali',
            'cancelled' => 'Tiket dibatalkan',
            default => 'Status diperbarui',
        };
    }
}
