<?php

$apiUrl = "https://xb.xhawala.com/testcon.php";
$apiKey = "8f4d9c7a2b1e6f3d9a0b5c1e7f2d4a6b";
$userAgent = "Render-WebSocket/1.0";

/**
 * Generic function to make a POST request to the API
 */
function callApi(string $purpose, array $data = []): ?array {
    global $apiUrl, $apiKey, $userAgent;

    $ch = curl_init($apiUrl);

    $postData = array_merge(['Data' => $data], ['Purpose' => $purpose]);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-API-KEY: $apiKey",
            "Content-Type: application/json",
            "User-Agent: $userAgent"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_TIMEOUT => 8
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        echo "CURL ERROR: " . curl_error($ch) . PHP_EOL;
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    //echo "Server response for '$purpose': " . $response . PHP_EOL;

    $data = json_decode($response, true);

    return is_array($data) ? $data : null;
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
    error_log("No of takens: ".$data);
    return isset($data['success'], $data['count']) && $data['success'] === true
        ? (int)$data['count']
        : 0;
}

function startTheGame(): bool {
    $data = callApi('startTheGame');
    return $data['success'] ?? false;
}
