<?php
/** lib/paymongo.php — PayMongo test/live mode detection + safety guard. */

/** 'test' | 'live' | 'missing' — decided by the secret key prefix (sk_test_ / sk_live_). */
function paymongo_key_mode(): string
{
    $key = (string)config('PAYMONGO_SECRET_KEY', '');
    if (str_starts_with($key, 'sk_test_')) return 'test';
    if (str_starts_with($key, 'sk_live_')) return 'live';
    return 'missing';
}

/**
 * Returns the secret key, or sends a clear JSON error. If .env says PAYMONGO_MODE=test
 * (the default here) a live key is REFUSED, so no real money can be charged while testing.
 * To go live later: set PAYMONGO_MODE=live AND use the sk_live_ key.
 */
function paymongo_secret_or_fail(): string
{
    $mode = paymongo_key_mode();
    $wanted = strtolower((string)config('PAYMONGO_MODE', 'test'));

    if ($mode === 'missing') {
        json_error('PayMongo is not configured: set PAYMONGO_SECRET_KEY (sk_test_...) in .env.', 500);
    }
    if ($wanted === 'test' && $mode !== 'test') {
        json_error('PAYMONGO_MODE is "test" but the secret key is not a test key (sk_test_...). Payment blocked for safety.', 500);
    }
    if ($wanted === 'live' && $mode !== 'live') {
        json_error('PAYMONGO_MODE is "live" but the secret key is not a live key (sk_live_...).', 500);
    }
    return (string)config('PAYMONGO_SECRET_KEY', '');
}

/**
 * Small JSON POST helper against the PayMongo v1 API, used by endpoints that
 * need to chain more than one call (e.g. the QR Ph flow: create payment
 * intent -> create payment method -> attach). Existing single-call
 * endpoints (create-gcash-source.php, webhook.php) keep their own inline
 * curl_init() code as-is; this just avoids repeating that boilerplate
 * three times in one file.
 *
 * @param string $path    e.g. '/payment_intents' or '/payment_intents/pi_xxx/attach'
 * @param array  $payload full request body, e.g. ['data' => ['attributes' => [...]]]
 * @return array{0:int,1:?array,2:?string} [httpStatus, decodedBody, curlErrorOrNull]
 *         httpStatus is 0 and decodedBody is null when the request itself failed
 *         (network/curl error) — check $error in that case.
 */
function paymongo_post(string $path, array $payload, string $secretKey): array
{
    $ch = curl_init('https://api.paymongo.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [0, null, $error !== '' ? $error : 'PayMongo request failed'];
    }

    $data = json_decode($raw, true);
    return [$status, is_array($data) ? $data : null, null];
}

/**
 * JSON GET helper against the PayMongo v1 API (e.g. '/payment_intents/pi_xxx').
 * Same return shape as paymongo_post(): [httpStatus, decodedBody, curlErrorOrNull].
 */
function paymongo_get(string $path, string $secretKey): array
{
    $ch = curl_init('https://api.paymongo.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [0, null, $error !== '' ? $error : 'PayMongo request failed'];
    }

    $data = json_decode($raw, true);
    return [$status, is_array($data) ? $data : null, null];
}

/** Pulls the human-readable PayMongo error detail out of a decoded error body, with a fallback. */
function paymongo_error_detail(?array $data, ?string $curlError, string $fallback): string
{
    if ($data && isset($data['errors'][0]['detail'])) {
        return (string)$data['errors'][0]['detail'];
    }
    return $curlError ?: $fallback;
}
