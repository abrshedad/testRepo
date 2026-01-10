<?php
$apiUrl = "https://xb.xhawala.com/testcon.php";

// Message to send
$msg = "Hello from Render at " . date('H:i:s');

// Data to send in POST body
$postData = [
    'msg' => $msg
];

$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "X-API-KEY: 8f4d9c7a2b1e6f3d9a0b5c1e7f2d4a6b",
        "Content-Type: application/x-www-form-urlencoded" // important for POST
    ],
    CURLOPT_TIMEOUT => 10,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($postData) // encode data as form
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
?>
