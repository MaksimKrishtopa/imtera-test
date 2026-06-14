<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Review;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class YandexMapsParser
{
    private Client $http;

    private const REVIEWS_PER_PAGE = 50;
    private const MAX_REVIEWS = 600;
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
            ],
            'verify' => false,
        ]);
    }

    /**
     * Extract Yandex organization ID from a Maps URL.
     * Supports formats:
     * - https://yandex.ru/maps/org/name/1234567890/
     * - https://yandex.com/maps/org/name/1234567890/
     * - https://yandex.ru/maps/-/anything
     * - https://2gis.ru/ (not supported, throws)
     */
    public function extractOrgId(string $url): string
    {
        if (!preg_match('#yandex\.(ru|com|by|kz|uz)#', $url)) {
            throw new \InvalidArgumentException('Ссылка должна быть на Яндекс.Карты (yandex.ru или yandex.com).');
        }

        // Pattern: /maps/org/{name}/{id}/ or /maps/{city}/org/{id}/
        if (preg_match('#/org/[^/]+/(\d{10,20})#', $url, $m)) {
            return $m[1];
        }

        // Pattern: oid= query param
        $parsed = parse_url($url);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (!empty($query['oid'])) {
                return $query['oid'];
            }
        }

        // Try to fetch the page and extract from meta/json
        return $this->extractOrgIdFromPage($url);
    }

    /**
     * Fetch page HTML and extract org ID from embedded JSON state.
     */
    private function extractOrgIdFromPage(string $url): string
    {
        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'Referer' => 'https://yandex.ru/maps/',
                ],
            ]);
            $html = (string) $response->getBody();
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Не удалось загрузить страницу организации: ' . $e->getMessage());
        }

        // Try extracting from JSON embedded in page
        if (preg_match('/"businessId"\s*:\s*"?(\d+)"?/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/"id"\s*:\s*"(\d{10,20})"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/oid=(\d+)/', $html, $m)) {
            return $m[1];
        }

        throw new \RuntimeException('Не удалось определить ID организации из ссылки. Проверьте, что ссылка ведёт на карточку организации.');
    }

    /**
     * Parse organization info and all reviews, save to DB.
     */
    public function parse(Organization $organization): void
    {
        $organization->update(['parse_status' => 'processing', 'parse_error' => null]);

        try {
            $orgId = $this->extractOrgId($organization->url);
            $organization->update(['yandex_id' => $orgId]);

            // Fetch first batch to get org info + initial reviews
            $firstBatch = $this->fetchReviewsBatch($orgId, 0, self::REVIEWS_PER_PAGE);

            $orgInfo = $firstBatch['orgInfo'] ?? [];
            $organization->update([
                'name' => $orgInfo['name'] ?? null,
                'rating' => $orgInfo['rating'] ?? null,
                'reviews_count' => $orgInfo['reviews_count'] ?? null,
                'ratings_count' => $orgInfo['ratings_count'] ?? null,
            ]);

            // Delete old reviews and save new ones
            $organization->reviews()->delete();
            $this->saveReviews($organization->id, $firstBatch['reviews']);

            $totalFetched = count($firstBatch['reviews']);
            $totalAvailable = $orgInfo['reviews_count'] ?? $totalFetched;
            $offset = self::REVIEWS_PER_PAGE;

            // Paginate through remaining reviews
            while ($totalFetched < min($totalAvailable, self::MAX_REVIEWS) && $offset < self::MAX_REVIEWS) {
                usleep(500000); // 0.5s delay to be polite

                $batch = $this->fetchReviewsBatch($orgId, $offset, self::REVIEWS_PER_PAGE);
                if (empty($batch['reviews'])) {
                    break;
                }

                $this->saveReviews($organization->id, $batch['reviews']);
                $totalFetched += count($batch['reviews']);
                $offset += self::REVIEWS_PER_PAGE;
            }

            $organization->update([
                'parse_status' => 'done',
                'parsed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('YandexMapsParser error', ['url' => $organization->url, 'error' => $e->getMessage()]);
            $organization->update([
                'parse_status' => 'error',
                'parse_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Fetch a batch of reviews from Yandex internal API.
     * Tries multiple endpoint strategies.
     */
    private function fetchReviewsBatch(string $orgId, int $offset, int $limit): array
    {
        // Strategy 1: Yandex Maps internal business reviews API
        try {
            return $this->fetchViaBusinessApi($orgId, $offset, $limit);
        } catch (\Throwable $e) {
            Log::warning('YandexMaps strategy 1 failed', ['error' => $e->getMessage()]);
        }

        // Strategy 2: Yandex Sprav API
        try {
            return $this->fetchViaSpravApi($orgId, $offset, $limit);
        } catch (\Throwable $e) {
            Log::warning('YandexMaps strategy 2 failed', ['error' => $e->getMessage()]);
        }

        throw new \RuntimeException('Не удалось получить отзывы. Яндекс заблокировал запрос или изменил API.');
    }

    /**
     * Strategy 1: Internal Yandex Maps business API.
     */
    private function fetchViaBusinessApi(string $orgId, int $offset, int $limit): array
    {
        $url = 'https://yandex.ru/maps/api/business/fetchReviews';

        $response = $this->http->get($url, [
            'query' => [
                'businessId' => $orgId,
                'from' => $offset,
                'limit' => $limit,
                'rating' => 0,
                'csrfToken' => '',
                'sessionId' => '',
            ],
            'headers' => [
                'Referer' => "https://yandex.ru/maps/org/{$orgId}/reviews/",
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (!$data) {
            throw new \RuntimeException('Пустой ответ от Яндекс API');
        }

        return $this->normalizeBusinessApiResponse($data, $orgId);
    }

    /**
     * Strategy 2: Yandex Sprav (справочник) API.
     */
    private function fetchViaSpravApi(string $orgId, int $offset, int $limit): array
    {
        $url = "https://yandex.ru/maps/-/api/business/{$orgId}/reviews";

        $response = $this->http->get($url, [
            'query' => [
                'from' => $offset,
                'limit' => $limit,
            ],
            'headers' => [
                'Referer' => "https://yandex.ru/maps/org/{$orgId}/reviews/",
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (!$data) {
            throw new \RuntimeException('Пустой ответ от Sprav API');
        }

        return $this->normalizeSpravApiResponse($data, $orgId);
    }

    private function normalizeBusinessApiResponse(array $data, string $orgId): array
    {
        $orgInfo = [];
        $reviews = [];

        // Extract org metadata
        if (!empty($data['data']['businessRating'])) {
            $rating = $data['data']['businessRating'];
            $orgInfo = [
                'name' => $data['data']['name'] ?? null,
                'rating' => isset($rating['stars']) ? (float) $rating['stars'] : null,
                'reviews_count' => $rating['reviewCount'] ?? $rating['reviews_count'] ?? null,
                'ratings_count' => $rating['ratingCount'] ?? $rating['ratings_count'] ?? null,
            ];
        } elseif (!empty($data['data']['rating'])) {
            $orgInfo = [
                'name' => $data['data']['name'] ?? null,
                'rating' => (float) $data['data']['rating'],
                'reviews_count' => $data['data']['reviewsCount'] ?? null,
                'ratings_count' => $data['data']['ratingsCount'] ?? null,
            ];
        }

        // Extract reviews
        $rawReviews = $data['data']['reviews'] ?? $data['reviews'] ?? [];
        foreach ($rawReviews as $r) {
            $reviews[] = $this->normalizeReview($r);
        }

        return ['orgInfo' => $orgInfo, 'reviews' => $reviews];
    }

    private function normalizeSpravApiResponse(array $data, string $orgId): array
    {
        $orgInfo = [];
        $reviews = [];

        if (!empty($data['rating'])) {
            $orgInfo = [
                'name' => $data['name'] ?? null,
                'rating' => (float) ($data['rating']['value'] ?? $data['rating']),
                'reviews_count' => $data['rating']['reviewCount'] ?? $data['reviewCount'] ?? null,
                'ratings_count' => $data['rating']['ratingCount'] ?? $data['ratingCount'] ?? null,
            ];
        }

        $rawReviews = $data['reviews'] ?? $data['data'] ?? [];
        foreach ($rawReviews as $r) {
            $reviews[] = $this->normalizeReview($r);
        }

        return ['orgInfo' => $orgInfo, 'reviews' => $reviews];
    }

    private function normalizeReview(array $r): array
    {
        $author = $r['author'] ?? $r['user'] ?? [];

        $text = $r['text'] ?? $r['comment'] ?? null;
        if (is_array($text)) {
            $text = implode(' ', $text);
        }

        $rating = $r['rating'] ?? $r['stars'] ?? null;
        if (is_array($rating)) {
            $rating = $rating['value'] ?? null;
        }

        $date = $r['updatedTime'] ?? $r['date'] ?? $r['time'] ?? $r['createdTime'] ?? null;
        $parsedDate = null;
        if ($date) {
            try {
                $parsedDate = is_numeric($date)
                    ? \Carbon\Carbon::createFromTimestamp((int)$date)
                    : \Carbon\Carbon::parse($date);
            } catch (\Throwable) {
                $parsedDate = null;
            }
        }

        return [
            'author_name' => $author['name'] ?? $r['userName'] ?? 'Аноним',
            'author_avatar' => $author['avatarUrl'] ?? $author['avatar'] ?? null,
            'rating' => $rating ? (int) $rating : null,
            'text' => $text,
            'reviewed_at' => $parsedDate,
            'yandex_review_id' => (string) ($r['id'] ?? $r['reviewId'] ?? ''),
        ];
    }

    private function saveReviews(int $organizationId, array $reviews): void
    {
        foreach ($reviews as $reviewData) {
            Review::create(array_merge($reviewData, ['organization_id' => $organizationId]));
        }
    }
}
