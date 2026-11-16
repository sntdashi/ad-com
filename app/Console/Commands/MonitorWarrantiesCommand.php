<?php

namespace App\Console\Commands;

use App\Services\Warranty\WarrantyService;
use Illuminate\Console\Command;

class MonitorWarrantiesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'warranty:monitor';

    /**
     * @var string
     */
    protected $description = 'Evaluate active warranties for approaching expiration or expiry and dispatch deduplicated alerts';

    public function handle(WarrantyService $warrantyService): int
    {
        $summary = $warrantyService->monitorWarranties();

        $this->info(
            "Warranty monitor evaluated {$summary['scanned']} warranty record(s); "
            ."expired={$summary['expired_marked']}, "
            ."expiring_notified={$summary['expiring_notified']}, "
            ."expired_notified={$summary['expired_notified']}."
        );

        return self::SUCCESS;
    }
}
