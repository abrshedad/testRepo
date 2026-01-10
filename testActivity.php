<?php

$apiUrl = "https://xb.xhawala.com/testcon.php"; 
/**
 * Bingo actions now use $conn (RemoteDB) instead of raw MySQL queries.
 * $conn is injected from connection.php
 */

function getDetail() {
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
        curl_close($ch);

        if ($response === false) {
            echo "CURL ERROR: " . curl_error($ch) . "\n";
            return;
        }

        $data = json_decode($response, true);
        return $data;
}
