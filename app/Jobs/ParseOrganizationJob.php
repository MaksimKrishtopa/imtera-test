<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\YandexMapsParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ParseOrganizationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 360;
    public int $tries = 1;

    public function __construct(
        public readonly Organization $organization,
    ) {}

    public function handle(YandexMapsParser $parser): void
    {
        Log::info('ParseOrganizationJob started', ['org_id' => $this->organization->id]);
        $parser->parse($this->organization);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ParseOrganizationJob failed', [
            'org_id' => $this->organization->id,
            'error' => $exception->getMessage(),
        ]);

        $this->organization->update([
            'parse_status' => 'error',
            'parse_error' => 'Задача завершилась с ошибкой: ' . $exception->getMessage(),
        ]);
    }
}
