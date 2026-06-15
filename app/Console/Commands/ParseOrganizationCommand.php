<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\YandexMapsParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ParseOrganizationCommand extends Command
{
    protected $signature   = 'app:parse-org {id : Organization ID}';
    protected $description = 'Parse Yandex Maps reviews for an organization';

    public function handle(YandexMapsParser $parser): int
    {
        $organization = Organization::find($this->argument('id'));

        if (!$organization) {
            $this->error('Organization not found.');
            return 1;
        }

        try {
            $parser->parse($organization);
            return 0;
        } catch (\Throwable $e) {
            Log::error('ParseOrganizationCommand failed', ['id' => $this->argument('id'), 'error' => $e->getMessage()]);
            $organization->update(['parse_status' => 'error', 'parse_error' => $e->getMessage()]);
            return 1;
        }
    }
}
