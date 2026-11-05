<?php

namespace App\Console\Commands;

use App\Models\EmergencyAllocation;
use App\Services\EmergencyAllocationService;
use Illuminate\Console\Command;

class CloseCareResponses extends Command
{
    protected $signature = 'careslot:close-responses';

    protected $description = 'Schließt abgelaufene Rückmeldungsphasen';

    public function handle(EmergencyAllocationService $service): int
    {
        EmergencyAllocation::where('status', 'responses')->where('response_deadline', '<=', now())->each(fn ($allocation) => $service->close($allocation, onlyIfExpired: true));

        return self::SUCCESS;
    }
}
