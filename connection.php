<?php
// connection.php - local wrapper for remote API

class RemoteDB {
    private $apiUrl;

    public function __construct($apiUrl = 'https://xb.xhawala.com/testConn.php') {
        $this->apiUrl = $apiUrl;
    }

    /**
     * Send a request to the remote API.
     * @param string $action The action to perform
     * @param array $params Optional parameters
     * @return mixed The decoded JSON response
     */
    private function request(string $action, array $params = []) {
        $params['action'] = $action;

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // if using HTTPS, adjust for prod

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception('API request error: ' . curl_error($ch));
        }

        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new Exception('Invalid JSON response: ' . $response);
        }

        return $decoded;
    }

    // Public API methods your server/bingoActions.php will use:

    public function getWinner() {
        return $this->request('getWinner');
    }

    public function updateWinnerPos(int $pos) {
        return $this->request('updateWinnerPos', ['pos' => $pos]);
    }

    public function checkNoOfPlayers() {
        return $this->request('checkNoOfPlayers');
    }

    public function startGame() {
        return $this->request('startGame');
    }

    public function payWinner(string $phone, int $totalWinners) {
        return $this->request('payWinner', [
            'phone' => $phone,
            'totalWinners' => $totalWinners
        ]);
    }

    public function discardCartela(string $phone, int $cartelaId) {
        return $this->request('discardCartela', [
            'phone' => $phone,
            'cartelaId' => $cartelaId
        ]);
    }

    public function checkBingoWinners(array $phoneCartelas, int $lastShownNumber) {
        return $this->request('checkBingoWinners', [
            'phoneCartelas' => $phoneCartelas,
            'lastShownNumber' => $lastShownNumber
        ]);
    }

    // Add more wrapper methods here as needed
}

// Instantiate global object
$conn = new RemoteDB();
