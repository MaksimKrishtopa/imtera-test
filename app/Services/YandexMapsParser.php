<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Review;
use Illuminate\Support\Facades\Log;

class YandexMapsParser
{
    private const MAX_REVIEWS = 570;

    public function extractOrgId(string $url): string
    {
        if (!preg_match('#yandex\.(ru|com|by|kz|uz|eu)#', $url)) {
            throw new \InvalidArgumentException('Ссылка должна вести на Яндекс.Карты (yandex.ru или yandex.com).');
        }

        $decoded = urldecode($url);

        if (preg_match('/oid=(\d{7,20})/', $decoded, $m)) {
            return $m[1];
        }

        if (preg_match('#/org/(?:[^/]+/)?(\d{7,20})#', $decoded, $m)) {
            return $m[1];
        }

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

    public function parse(Organization $organization): void
    {
        $organization->update(['parse_status' => 'processing', 'parse_error' => null]);

        try {
            $orgId = $this->extractOrgId($organization->url);
            $organization->update(['yandex_id' => $orgId]);

            $reviewsUrl = $this->buildReviewsUrl($organization->url, $orgId);

            $firstBatch = true;

            $this->runNodeScraper(
                $reviewsUrl,
                self::MAX_REVIEWS,
                function (string $type, array $payload) use ($organization, &$firstBatch): void {
                    if ($type === 'info') {
                        $info = $payload['org_info'] ?? [];
                        if (!empty($info['name']) || !empty($info['rating'])) {
                            $organization->update([
                                'name'          => $info['name'] ?? $organization->name,
                                'rating'        => $info['rating'] ?? null,
                                'reviews_count' => $info['reviews_count'] ?? null,
                                'ratings_count' => $info['ratings_count'] ?? null,
                            ]);
                        }
                    } elseif ($type === 'batch') {
                        if ($firstBatch) {
                            $organization->reviews()->delete();
                            $firstBatch = false;
                        }
                        foreach ($payload['reviews'] ?? [] as $data) {
                            $this->createReview($organization->id, $data);
                        }
                    }
                }
            );

            $organization->update([
                'parse_status' => 'done',
                'parsed_at'    => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error('YandexMapsParser error', ['url' => $organization->url, 'error' => $e->getMessage()]);
            $organization->update(['parse_status' => 'error', 'parse_error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function buildReviewsUrl(string $originalUrl, string $orgId): string
    {
        $decoded = urldecode($originalUrl);

        if (preg_match('|/org/([^/]+/\d{7,20})|', $decoded, $m)) {
            $withoutQuery = strtok($decoded, '?');
            $withoutQuery = strtok($withoutQuery, '#');
            $base = rtrim(preg_replace('|/org/.*|', '/org/' . $m[1], $withoutQuery), '/');
            return $base . '/reviews/';
        }

        return "https://yandex.ru/maps/org/{$orgId}/reviews/";
    }

    private function runNodeScraper(string $reviewsUrl, int $maxReviews, callable $onMessage): void
    {
        $scriptPath = str_replace('/', DIRECTORY_SEPARATOR, base_path('scripts/scrape-reviews.cjs'));

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException('Скрипт парсера не найден: ' . $scriptPath);
        }

        $isWindows = PHP_OS_FAMILY === 'Windows';

        if ($isWindows) {
            $errFile = sys_get_temp_dir() . '\scraper-err-' . getmypid() . '.log';
            $cmd = sprintf(
                'node %s %s %s 2> %s',
                escapeshellarg($scriptPath),
                escapeshellarg($reviewsUrl),
                escapeshellarg((string) $maxReviews),
                escapeshellarg($errFile)
            );
        } else {
            $cmd = sprintf(
                'node %s %s %s 2>/dev/null',
                escapeshellarg($scriptPath),
                escapeshellarg($reviewsUrl),
                escapeshellarg((string) $maxReviews)
            );
        }

        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
        ], $pipes, null);

        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить скрипт парсера.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $timeout = 450;
        $start   = time();
        $buf     = '';

        while (true) {
            $status = proc_get_status($process);

            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
                while (($pos = strpos($buf, "\n")) !== false) {
                    $line = substr($buf, 0, $pos);
                    $buf  = substr($buf, $pos + 1);
                    $this->processScraperLine($line, $onMessage);
                }
            }

            if (!$status['running']) break;

            if (time() - $start > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                proc_close($process);
                throw new \RuntimeException('Парсинг завершился по таймауту (' . $timeout . ' сек).');
            }

            usleep(200000);
        }

        while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
            $buf .= $chunk;
        }
        fclose($pipes[1]);
        proc_close($process);

        foreach (explode("\n", $buf) as $line) {
            $this->processScraperLine($line, $onMessage);
        }

        if ($isWindows && isset($errFile)) {
            @unlink($errFile);
        }
    }

    private function processScraperLine(string $line, callable $onMessage): void
    {
        $line = trim($line);
        if (!$line || $line[0] !== '{') return;

        $data = json_decode($line, true);
        if (!$data) return;

        if (isset($data['error'])) {
            throw new \RuntimeException('Ошибка парсера: ' . $data['error']);
        }

        $type = $data['type'] ?? null;
        if ($type) {
            $onMessage($type, $data);
        }
    }

    private function createReview(int $organizationId, array $data): void
    {
        Review::create([
            'organization_id' => $organizationId,
            'author_name'     => $data['author_name'] ?? 'Аноним',
            'author_avatar'   => $data['author_avatar'] ?? null,
            'rating'          => isset($data['rating']) ? (int) $data['rating'] : null,
            'text'            => $data['text'] ?? null,
            'reviewed_at'     => $data['reviewed_at'] ? \Carbon\Carbon::parse($data['reviewed_at']) : null,
            'yandex_review_id' => $data['yandex_review_id'] ?? null,
        ]);
    }
}
