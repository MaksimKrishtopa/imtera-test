<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Review;
use Illuminate\Support\Facades\Log;

class YandexMapsParser
{
    private const MAX_REVIEWS = 600;

    /**
     * Extract Yandex organization ID from a Maps URL.
     * Supports all known URL formats:
     * - https://yandex.ru/maps/org/name/1234567890/reviews/
     * - https://yandex.ru/maps/org/1234567890/
     * - https://yandex.ru/maps/213/city/?poi[uri]=ymapsbm1://org?oid=1234567890
     * - https://yandex.ru/maps/?oid=1234567890
     */
    public function extractOrgId(string $url): string
    {
        if (!preg_match('#yandex\.(ru|com|by|kz|uz|eu)#', $url)) {
            throw new \InvalidArgumentException('Ссылка должна вести на Яндекс.Карты (yandex.ru или yandex.com).');
        }

        // Decode any percent-encoding so all formats are handled uniformly
        $decoded = urldecode($url);

        // Format: poi[uri]=ymapsbm1://org?oid=1234567890  (map POI links with encoded URI)
        if (preg_match('/oid=(\d{7,20})/', $decoded, $m)) {
            return $m[1];
        }

        // Format: /org/{slug}/{id}/ or /org/{id}/
        if (preg_match('#/org/(?:[^/]+/)?(\d{7,20})#', $decoded, $m)) {
            return $m[1];
        }

        // Format: ?oid= query param (direct URL)
        $parsed = parse_url($decoded);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (!empty($query['oid']) && is_numeric($query['oid'])) {
                return $query['oid'];
            }
        }

        throw new \InvalidArgumentException(
            'Не удалось извлечь ID организации из URL. ' .
            'Поддерживаемые форматы: /maps/org/название/ID/ или ссылки из меню «Поделиться» в Яндекс.Картах.'
        );
    }

    /**
     * Parse organization reviews and info, save to database.
     * Uses Node.js + Playwright for bot-protection bypass.
     */
    public function parse(Organization $organization): void
    {
        $organization->update(['parse_status' => 'processing', 'parse_error' => null]);

        try {
            $orgId = $this->extractOrgId($organization->url);
            $organization->update(['yandex_id' => $orgId]);

            // Build a canonical reviews URL preserving the slug (if present).
            // Navigating to org/{id}/reviews/ without slug causes Yandex to
            // redirect, which closes the Playwright page context and crashes.
            $reviewsUrl = $this->buildReviewsUrl($organization->url, $orgId);

            $result = $this->runNodeScraper($reviewsUrl, self::MAX_REVIEWS);

            // Save org info
            $orgInfo = $result['org_info'] ?? [];
            if (!empty($orgInfo['name']) || !empty($orgInfo['rating'])) {
                $organization->update([
                    'name' => $orgInfo['name'] ?? $organization->name,
                    'rating' => $orgInfo['rating'] ?? null,
                    'reviews_count' => $orgInfo['reviews_count'] ?? null,
                    'ratings_count' => $orgInfo['ratings_count'] ?? null,
                ]);
            }

            // Save reviews (replace old with new atomically)
            $reviews = $result['reviews'] ?? [];
            $organization->reviews()->delete();

            foreach ($reviews as $reviewData) {
                $this->createReview($organization->id, $reviewData);
            }

            // Update reviews_count if not set from org_info
            if (!$organization->reviews_count && count($reviews) > 0) {
                $organization->update(['reviews_count' => count($reviews)]);
            }

            $organization->update([
                'parse_status' => 'done',
                'parsed_at' => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error('YandexMapsParser error', [
                'url' => $organization->url,
                'error' => $e->getMessage(),
            ]);
            $organization->update([
                'parse_status' => 'error',
                'parse_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build the canonical Yandex Maps reviews URL preserving the org slug.
     * Passing a numeric-only URL (org/{id}/reviews/) causes Yandex to redirect,
     * which closes the Playwright page context and crashes the scraper.
     */
    private function buildReviewsUrl(string $originalUrl, string $orgId): string
    {
        $decoded = urldecode($originalUrl);

        // Extract slug + id if present: /org/{slug}/{id}
        if (preg_match('|/org/([^/]+/\d{7,20})|', $decoded, $m)) {
            // Strip query string and fragment, replace org path
            $withoutQuery = strtok($decoded, '?');
            $withoutQuery = strtok($withoutQuery, '#');
            // Replace everything after /org/ with slug/id
            $base = rtrim(preg_replace('|/org/.*|', '/org/' . $m[1], $withoutQuery), '/');
            return $base . '/reviews/';
        }

        // Fallback: numeric-only (will redirect but that's unavoidable)
        return "https://yandex.ru/maps/org/{$orgId}/reviews/";
    }

    /**
     * Run the Node.js Playwright scraper script.
     * Accepts the full reviews URL (not just orgId) to avoid Yandex redirects.
     */
    private function runNodeScraper(string $reviewsUrl, int $maxReviews): array
    {
        $scriptPath = str_replace('/', DIRECTORY_SEPARATOR, base_path('scripts/scrape-reviews.cjs'));

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException('Скрипт парсера не найден: ' . $scriptPath);
        }

        // Use URL as part of temp filename (sanitized)
        $safeId = preg_replace('/[^a-z0-9]/', '_', parse_url($reviewsUrl, PHP_URL_PATH) ?? 'org');
        $outFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yandex_scraper_' . substr($safeId, -30) . '_' . getmypid() . '.json';

        // Build command with output redirected to temp file
        $isWindows = PHP_OS_FAMILY === 'Windows';
        if ($isWindows) {
            $cmd = sprintf(
                'cmd /c node %s %s %s > %s 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($reviewsUrl),
                escapeshellarg((string) $maxReviews),
                escapeshellarg($outFile)
            );
        } else {
            $cmd = sprintf(
                'node %s %s %s > %s 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($reviewsUrl),
                escapeshellarg((string) $maxReviews),
                escapeshellarg($outFile)
            );
        }

        Log::info('Running node scraper', ['url' => $reviewsUrl, 'outFile' => $outFile]);

        $timeout = 450;
        $start = time();

        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null);

        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить скрипт парсера.');
        }

        fclose($pipes[0]);
        // stdout/stderr both go to file via shell redirect, just drain in case
        fclose($pipes[1]);
        fclose($pipes[2]);

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) break;

            if (time() - $start > $timeout) {
                proc_terminate($process);
                @unlink($outFile);
                throw new \RuntimeException('Парсинг завершился по таймауту (' . $timeout . ' сек).');
            }

            sleep(1);
        }

        proc_close($process);

        if (!file_exists($outFile)) {
            throw new \RuntimeException('Скрипт парсера не создал выходной файл.');
        }

        $stdout = trim(file_get_contents($outFile));
        @unlink($outFile);

        if (empty($stdout)) {
            throw new \RuntimeException('Парсер не вернул данные.');
        }

        // Find the JSON line (last non-empty line in case of debug output before it)
        $lines = array_filter(array_map('trim', explode("\n", $stdout)));
        $jsonLine = '';
        foreach (array_reverse(array_values($lines)) as $line) {
            if (str_starts_with($line, '{')) {
                $jsonLine = $line;
                break;
            }
        }

        if (!$jsonLine) {
            throw new \RuntimeException('Неверный формат ответа от парсера: ' . substr($stdout, 0, 300));
        }

        $data = json_decode($jsonLine, true);
        if ($data === null) {
            throw new \RuntimeException('Неверный JSON от парсера: ' . substr($jsonLine, 0, 200));
        }

        if (isset($data['error'])) {
            throw new \RuntimeException('Ошибка парсера: ' . $data['error']);
        }

        if (empty($data['reviews'])) {
            Log::warning('Node scraper returned no reviews', ['orgId' => $orgId]);
        }

        return $data;
    }

    private function createReview(int $organizationId, array $data): void
    {
        Review::create([
            'organization_id' => $organizationId,
            'author_name' => $data['author_name'] ?? 'Аноним',
            'author_avatar' => $data['author_avatar'] ?? null,
            'rating' => isset($data['rating']) ? (int) $data['rating'] : null,
            'text' => $data['text'] ?? null,
            'reviewed_at' => $data['reviewed_at'] ? \Carbon\Carbon::parse($data['reviewed_at']) : null,
            'yandex_review_id' => $data['yandex_review_id'] ?? null,
        ]);
    }
}
