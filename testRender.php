<?php
$apiUrl = "https://xb.xhawala.com/testcon.php";

// Optional: send a test message
$msg = "Hello from Render at " . date('H:i:s');
$apiUrlWithMsg = $apiUrl . "?msg=" . urlencode($msg);

$ch = curl_init($apiUrlWithMsg);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "X-API-KEY: 8f4d9c7a2b1e6f3d9a0b5c1e7f2d4a6b"
    ],
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);

if ($response === false) {
    echo "Curl error: " . curl_error($ch);
    exit;
}

$data = json_decode($response, true);

if (!$data) {
    echo "Invalid JSON response: " . $response;
    exit;
}

echo "API Response:\n";
print_r($data);
