<?php

$apiUrl = "https://xb.xhawala.com/testcon.php";

function getDetail() {
    global $apiUrl;

    $ch = curl_init($apiUrl);

    $postData = [
        'Purpose' => 'LoadStatus'
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-API-KEY: 8f4d9c7a2b1e6f3d9a0b5c1e7f2d4a6b",
            "Content-Type: application/json",
            "User-Agent: Render-WebSocket/1.0"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        echo "CURL ERROR: " . curl_error($ch) . "\n";
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $data = json_decode($response, true);
    return $data;
}

function afterGoodBingoAction(): bool
{
    global $apiUrl;

    $ch = curl_init($apiUrl);

    $postData = [
        'Purpose' => 'afterGoodBingoAction'
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "X-API-KEY: " . getenv('BINGO_API_KEY'),
            "Content-Type: application/json",
            "User-Agent: Render-WebSocket/1.0"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    $data = json_decode($response, true);

    return is_array($data) && ($data['success'] ?? false) === true;
}


