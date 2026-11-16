<?php

namespace App\Console\Commands;

use App\Services\Sla\SlaService;
use Illuminate\Console\Command;

class MonitorSlaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sla:monitor';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate open service orders for approaching or breached SLA deadlines and dispatch notifications';

    public function handle(SlaService $slaService): int
    {
        $summary = $slaService->monitorOpenOrders();

        $this->info("SLA monitor evaluated {$summary['scanned']} open order(s); dispatched {$summary['notifications_sent']} notification event(s).");

        return self::SUCCESS;
    }
}
