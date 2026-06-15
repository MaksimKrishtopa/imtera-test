<?php
require 'vendor/autoload.php';

$jar = new \GuzzleHttp\Cookie\CookieJar();
$client = new \GuzzleHttp\Client([
    'verify' => false,
    'cookies' => $jar,
    'allow_redirects' => true,
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
    ],
]);

$orgId = '21117108341';

// Load page to get session
$resp = $client->get("https://yandex.ru/maps/org/{$orgId}/reviews/");
$html = (string)$resp->getBody();
preg_match('/<script[^>]*>(\{"config".+?\})<\/script>/sU', $html, $m);
$config = json_decode($m[1], true)['config'] ?? [];
$csrfToken = $config['csrfToken'] ?? '';
$xscriptToken = $config['xscriptCsrfToken'] ?? '';

echo "CSRF: $csrfToken" . PHP_EOL;

// Test yandex.COM (international) domain
echo PHP_EOL . "=== yandex.COM domain ===" . PHP_EOL;
try {
    $r = $client->post('https://yandex.com/maps/api/business/fetchReviews', [
        'body' => "businessId={$orgId}&from=0&limit=5&rating=0&csrfToken=" . $csrfToken,
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json, */*',
            'Origin' => 'https://yandex.com',
            'Referer' => "https://yandex.com/maps/org/{$orgId}/reviews/",
            'X-Requested-With' => 'XMLHttpRequest',
        ],
    ]);
    $body = (string)$r->getBody();
    $data = json_decode($body, true);
    echo "HTTP: " . $r->getStatusCode() . PHP_EOL;
    echo "Keys: " . implode(', ', array_keys($data ?? [])) . PHP_EOL;
    echo substr($body, 0, 500) . PHP_EOL;
} catch (Exception $e) { echo "Error: " . $e->getMessage() . PHP_EOL; }

// Load yandex.COM version and get its CSRF
echo PHP_EOL . "=== Loading yandex.COM page ===" . PHP_EOL;
$jar2 = new \GuzzleHttp\Cookie\CookieJar();
$client2 = new \GuzzleHttp\Client(['verify' => false, 'cookies' => $jar2, 'allow_redirects' => true, 'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36', 'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8']]);

$comResp = $client2->get("https://yandex.com/maps/org/{$orgId}/reviews/");
$comHtml = (string)$comResp->getBody();
preg_match('/<script[^>]*>(\{"config".+?\})<\/script>/sU', $comHtml, $m2);
$comConfig = json_decode($m2[1], true)['config'] ?? [];
$comCsrf = $comConfig['csrfToken'] ?? '';
echo "COM CSRF: $comCsrf" . PHP_EOL;

$r2 = $client2->post('https://yandex.com/maps/api/business/fetchReviews', [
    'body' => "businessId={$orgId}&from=0&limit=5&rating=0&csrfToken=" . $comCsrf,
    'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json, */*',
        'Origin' => 'https://yandex.com',
        'Referer' => "https://yandex.com/maps/org/{$orgId}/reviews/",
        'X-Requested-With' => 'XMLHttpRequest',
        'Sec-Fetch-Site' => 'same-origin',
        'Sec-Fetch-Mode' => 'cors',
    ],
]);
$body2 = (string)$r2->getBody();
$data2 = json_decode($body2, true);
echo "HTTP: " . $r2->getStatusCode() . PHP_EOL;
echo "Keys: " . implode(', ', array_keys($data2 ?? [])) . PHP_EOL;
echo substr($body2, 0, 2000) . PHP_EOL;

// Try the sprav.yandex API
echo PHP_EOL . "=== Sprav API ===" . PHP_EOL;
$spravEndpoints = [
    "https://sprav.yandex.ru/v1/org/{$orgId}/reviews?from=0&limit=5",
    "https://sprav.yandex.com/v1/org/{$orgId}/reviews?from=0&limit=5",
    "https://yandex.com/sprav/v1/org/{$orgId}/reviews?from=0&limit=5",
    "https://yandex.com/sprav/{$orgId}/reviews",
];
foreach ($spravEndpoints as $url) {
    try {
        $r3 = $client->get($url, ['headers' => ['Accept' => 'application/json', 'Referer' => 'https://yandex.com/maps/']]);
        echo "$url -> HTTP " . $r3->getStatusCode() . ": " . substr((string)$r3->getBody(), 0, 200) . PHP_EOL;
    } catch (Exception $e) {
        $errMsg = explode("\n", $e->getMessage())[0];
        echo "$url -> Error: " . substr($errMsg, 0, 150) . PHP_EOL;
    }
}
