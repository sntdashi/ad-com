<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\FonnteMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsAppNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $deliveryId,
        public string $message,
    ) {}

    public function handle(FonnteMessageSender $sender): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->recipient_phone === null) {
            return;
        }

        $delivery->forceFill(['status' => 'processing'])->save();

        $result = $sender->sendOperationalMessage($delivery->recipient_phone, $this->message);

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'status' => $result['status'],
            'last_error' => $result['error'],
            'sent_at' => $result['sent'] ? now() : null,
        ])->save();

        if (! $result['sent'] && $result['status'] === 'failed' && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
        }
    }
}
