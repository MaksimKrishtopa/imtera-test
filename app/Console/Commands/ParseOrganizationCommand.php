<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\YandexMapsParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ParseOrganizationCommand extends Command
{
    protected $signature = 'app:parse-org {id : Organization ID}';
    protected $description = 'Parse Yandex Maps reviews for an organization';

    public function handle(YandexMapsParser $parser): int
    {
        $id = $this->argument('id');
        $organization = Organization::find($id);

        if (! $organization) {
            $this->error("Organization #{$id} not found.");
            return 1;
        }

        $this->info("Parsing organization #{$id}: {$organization->url}");

        try {
            $parser->parse($organization);
            $this->info("Done. Status: {$organization->fresh()->parse_status}");
            return 0;
        } catch (\Throwable $e) {
            Log::error("ParseOrganizationCommand failed for #{$id}: " . $e->getMessage());
            $organization->update([
                'parse_status' => 'error',
                'parse_error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());
            return 1;
        }
    }
}
