<?php

namespace App\Actions\Orders;

use App\Models\OrderMessage;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Sla\SlaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SendOrderMessage
{
    public function handle(ServiceOrder $order, User $sender, string $body): OrderMessage
    {
        $sla = app(SlaService::class);

        return DB::transaction(function () use ($order, $sender, $body, $sla): OrderMessage {
            $lockedOrder = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);
            Gate::forUser($sender)->authorize('view', $lockedOrder);
            $recipientId = $sender->role === 'customer' ? $lockedOrder->assigned_technician_id : $lockedOrder->customer_id;
            if ($sender->role === 'customer' && $recipientId === null) {
                throw ValidationException::withMessages(['body' => 'Chat aktif setelah PIC teknisi ditugaskan ke tiket ini.']);
            }
            if (trim($body) === '') {
                throw ValidationException::withMessages(['body' => 'Tulis pesan terlebih dahulu.']);
            }

            if ($sender->role !== 'customer') {
                $sla->recordFirstResponse($lockedOrder);
            } elseif ($lockedOrder->sla_paused_at !== null) {
                $sla->resume($lockedOrder, $sender, null, 'customer_response');
            }

            return $lockedOrder->messages()->create([
                'sender_id' => $sender->id,
                'recipient_id' => $recipientId,
                'body' => trim($body),
            ]);
        });
    }
}
