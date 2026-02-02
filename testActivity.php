<?php

$apiUrl   = "https://xb.xhawala.com/testcon.php";
$apiKey   = "8f4d9c7a2b1e6f3d9a0b5c1e7f2d4a6b";
$userAgent = "Render-WebSocket/1.0";

/**
 * Generic function to make a POST request to the API
 */
function callApi(string $purpose, array $data = [], int $timeout = 8, int $retries = 3): ?array {
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
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);

        if ($response !== false) {
            curl_close($ch);
            $decoded = json_decode($response, true);
            if (is_array($decoded)) return $decoded;
            error_log("Invalid JSON returned from API (Purpose: $purpose): $response");
            return null;
        }

        $error = curl_error($ch);
        curl_close($ch);

        if ($attempt === $retries) {
            error_log("CURL ERROR (Purpose: $purpose, Attempt $attempt): $error");
            return null;
        }

        // Wait a short time before retry
        usleep(200000); // 200ms
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
