<?php

$apiUrl   = "https://xb.xhawala.com/testcon.php";
$apiKey   = "8f4d9c7a2b1e6f3d9a0b5c1e7f2d4a6b";
$userAgent = "Render-WebSocket/1.0";

/**
 * Generic function to make a POST request to the API
 */
function callApi(string $purpose, array $data = [], int $timeout = 15, int $retries = 3): ?array {
    global $apiUrl, $apiKey, $userAgent;

    $postData = ['Purpose' => $purpose, 'Data' => $data];

    for ($attempt = 1; $attempt <= $retries; $attempt++) {

        $ch = curl_init($apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "X-API-KEY: $apiKey",
                "Content-Type: application/json",
                "User-Agent: $userAgent"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),

            // Increased time limits
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,

            // Better stability
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);

        if ($response !== false) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode($response, true);
                if (is_array($decoded)) return $decoded;

                error_log("Invalid JSON returned from API (Purpose: $purpose): $response");
                return null;
            }

            error_log("HTTP ERROR $httpCode (Purpose: $purpose): $response");
        } else {
            $error = curl_error($ch);
            error_log("CURL ERROR (Purpose: $purpose, Attempt $attempt): $error");
        }

        curl_close($ch);

        // Exponential backoff instead of fixed delay
        if ($attempt < $retries) {
            usleep(300000 * $attempt); // 300ms, 600ms, 900ms
        }
    }

    return null;
}

/**
 * Wrapper functions for specific purposes
 */
function getDetail(): ?array {
    return callApi('LoadStatus');
}

function afterGoodBingoAction(): bool {
    $data = callApi('afterGoodBingoAction');
    return $data['success'] ?? false;
}

function checkIfAllCartelasTaken(): bool {
    $data = callApi('checkIfAllAreTaken');
    return $data['success'] ?? false;
}

function checkNoOfPlayers(): int {
    $data = callApi('checkNoOfPlayers');
    return isset($data['success'], $data['count']) && $data['success'] === true
        ? (int)$data['count']
        : 0;
}

function startTheGame(): bool {
    $data = callApi('startTheGame');
    return $data['success'] ?? false;
}
