<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/**
 * This file acts as a proxy to the remote API at xb.xhawala.com
 * All database-like actions are sent to the remote server.
 */

class RemoteDB
{
    private $apiBase = "https://xb.xhawala.com/connection.php";

    // Generic GET or POST request
    private function request($endpoint, $data = [], $method = "POST") {
        $url = $this->apiBase . "?action=" . urlencode($endpoint);

        $ch = curl_init();
        if ($method === "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            if (!empty($data)) {
                $url .= "&" . http_build_query($data);
            }
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception("Remote API error: " . curl_error($ch));
        }
        curl_close($ch);

        return json_decode($response, true);
    }

    // Example: Query all cartelas
    public function queryCartelas($params = []) {
        return $this->request("getCartelas", $params);
    }

    // Example: Check number of players
    public function checkNoOfPlayers() {
        $res = $this->request("checkNoOfPlayers");
        return $res['count'] ?? 0;
    }

    // Example: Start the game
    public function startTheGame() {
        $res = $this->request("startTheGame");
        return $res['success'] ?? false;
    }

    // Example: Check bingo winners
    public function checkBingoWinners($phoneCartelas, $lastNumber) {
        return $this->request("checkBingoWinners", [
            "phoneCartelas" => $phoneCartelas,
            "lastNumber" => $lastNumber
        ]);
    }

    // Example: Pay winner
    public function payWinner($phone, $totalWinners) {
        return $this->request("payWinner", [
            "phone" => $phone,
            "totalWinners" => $totalWinners
        ]);
    }

    // Example: Discard a cartela
    public function discardCartela($phone, $cartelaId) {
        return $this->request("discardCartela", [
            "phone" => $phone,
            "cartelaId" => $cartelaId
        ]);
    }

    // Add any other actions you need, like isNoCartelaTaken(), etc.
}

// Now you can use it as $conn
$conn = new RemoteDB();
