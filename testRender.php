<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/testActivity.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\Server as ReactServer;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class GameServer implements MessageComponentInterface {

    protected $clients;
    protected $loop;
    protected $testTimer;
    protected $apiUrl;
    protected $lastInsertId = 0;
    protected $sentNumbers = [];
    protected $timer;
    protected $running = false;
    protected $paused = false;
    protected $refresh = false;
    protected $lastShownNumber = 0 ;
    protected $goodBingoTimer;
    protected $gameStartTimer;
    protected $conn;
    protected $gameSpeed = 3;

    public function __construct($loop, $apiUrl) {
        $this->clients = new \SplObjectStorage;
        $this->loop = $loop;
        $this->testTimer = $loop;
        $this->apiUrl = $apiUrl;

        // Timer 1 (kept exactly as-is)
        $this->loop->addPeriodicTimer(5, function () {
            echo "fetch data\n";
            // $this->fetchDataFromApi();
            //$res = getDetail();
            //print_r($res);
        });
    }

    /** 🔄 Fetch data from testcon.php API (unused, left intact) */
    protected function fetchDataFromApi() {
        $ch = curl_init($this->apiUrl);

        $postData = [
            'Purpose' => 'getData'
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
            return;
        }

        curl_close($ch);

        $data = json_decode($response, true);
        if (!$data || !isset($data['latest_rows'])) {
            echo "Invalid JSON from API: " . $response . "\n";
            return;
        }

        foreach ($data['latest_rows'] as $row) {
            $id = $row['No'];
            $num = $row['UserName'];

            echo $id . " ::: " . $num . "\n";

            $this->lastInsertId = $id;
            $this->sentNumbers[] = $num;

            $msg = json_encode([
                'type' => 'number',
                'value' => $num,
                'all' => $this->sentNumbers
            ]);

            foreach ($this->clients as $client) {
                $client->send($msg);
            }
        }
    }

    /** 🔁 START POST-GAME TIMER WITH RETRY LOGIC */
    private function startPostGameTimer($secs) {
        if ($this->goodBingoTimer) {
            $this->loop->cancelTimer($this->goodBingoTimer);
        }

        echo "⏳ 15-second post-game timer started...\n";

        $this->goodBingoTimer = $this->loop->addTimer($secs, function () {
            $success = afterGoodBingoAction();

            if (!$success) {
                echo "🔁 Action failed — retrying in 5s\n";
                // Retry after 5 seconds
                $this->loop->addTimer(5, function () {
                    $this->startPostGameTimer(0);
                });
                return;
            }

            echo "📢 Sending refresh message to all clients\n";

            $refreshMessage = json_encode([
                'type'    => 'refresh',
                'message' => 'Betting is started... &#128523;'
            ]);

            foreach ($this->clients as $client) {
                $client->send($refreshMessage);
            }

            $this->startBetting(40);
        });
    }

    /** 🔁 START BETTING TIMER WITH RETRY LOGIC */
    private function startBetting($secs) {
        $this->running = false;
        $this->paused = false;
        $this->refresh = true;
        $this->startPeriodicRefresh($secs);

        if ($this->gameStartTimer) {
            $this->loop->cancelTimer($this->gameStartTimer);
        }

        echo "⏳ 1-minute post-game timer started...\n";

        $this->gameStartTimer = $this->loop->addTimer(40, function () {
            // Check if all cartelas are taken
            if (checkIfAllCartelasTaken($this->conn)) {
                echo "✅ All cartelas taken — starting game immediately\n";
                $this->startGameImmediately();
                return;
            }

            $this->startGameImmediately();
        });
    }

    private function startPeriodicRefresh($secs) {
        // Periodic refresh for inactive betting
        static $countdownTimer = null; // track the countdown timer
        static $remainingTime = 0;

        // Cancel existing countdown if running
        if ($countdownTimer) {
            $this->loop->cancelTimer($countdownTimer);
            $countdownTimer = null;
        }

        $remainingTime = $secs;

        // Start a new countdown timer
        $countdownTimer = $this->loop->addPeriodicTimer(1, function() use (&$remainingTime, &$countdownTimer) {
            if ($remainingTime <= 0) {
                echo "\n⏱ Countdown finished.\n";
                $this->loop->cancelTimer($countdownTimer);
                $countdownTimer = null;
                return;
            }

            // Send remaining time to all clients
            $refreshMessage = json_encode([
                'type' => 'refresh',
                'message' => 'Betting is started... &#128523;',
                'remainingTime' => $remainingTime
            ]);

            foreach ($this->clients as $client) {
                $client->send($refreshMessage);
            }

            //echo "⏱ Time remaining: {$remainingTime}s\r";
            $remainingTime--;
        });
    }

    private function startGameImmediately() {
        // 1️⃣ Check number of players before starting
        $numPlayers = checkNoOfPlayers($this->conn);
        if ($numPlayers <= 1) {
            echo "⚠️ Not enough players ($numPlayers) — restarting timer\n";
            $this->startBetting(40); // or any default timer seconds you want
            return; // stop further execution
        }

        // 2️⃣ Cancel any existing timer
        if ($this->gameStartTimer) {
            $this->loop->cancelTimer($this->gameStartTimer);
            $this->gameStartTimer = null;
        }

        $success = startTheGame();

        if (!$success) {
            echo "🔁 Action failed — retrying in 5s\n";
            $this->loop->addTimer(5, function () {
                $this->startBetting(0);
            });
            return;
        }

        echo "📢 Bingo is now starting\n";

        // 3️⃣ Start the game
        $this->running = true;
        $this->paused = false;
        $this->refresh = false;
        $this->sentNumbers = [];

        $startMessage = json_encode(['type' => 'start']);
        foreach ($this->clients as $c) {
            $c->send($startMessage);
        }

        $this->startGame();
    }
    
    /** 🔢 START GAME NUMBERS */
    public function startGame() {
    
        if ($this->timer) {
            $this->loop->cancelTimer($this->timer);
            $this->lastShownNumber = 0;
        }
    
        $this->timer = $this->loop->addPeriodicTimer($this->gameSpeed, function () {
    
            if (!$this->running || $this->paused) {
                return;
            }
    
            // 🔹 Fetch the winners string and current position from the API
            $apiResponse = callApi('getCurrentWinners'); // custom API endpoint
            if (!$apiResponse || !isset($apiResponse['success']) || $apiResponse['success'] !== true) {
                error_log("Failed to fetch winners from API.");
                return;
            }
    
            $res = $apiResponse['Winners'];            // winners string
            $cpos = (int)$apiResponse['NoOfWinnersShown']; // current position
    
            if ($cpos >= strlen($res)) {
                error_log("All numbers shown. Stopping.");
    
                if ($this->timer) {
                    $this->loop->cancelTimer($this->timer);
                    $this->timer = null;
                }
    
                // Optional: start post-game actions if no cartela is taken
                $cartelaResponse = callApi('isNoCartelaTaken');
                if ($cartelaResponse && isset($cartelaResponse['success']) && $cartelaResponse['success'] === true) {
                    $this->running = true;
                    $this->paused = false;
                    $this->refresh = false;
                    $this->sentNumbers = [];
    
                    $this->startPostGameTimer(5);
                }
    
                return;
            }
    
            $numStr = substr($res, $cpos, 2);
            $random = (int)$numStr;
            $this->sentNumbers[] = $random;
    
            $message = json_encode([
                'type' => 'number',
                'value' => $random,
                'all' => $this->sentNumbers
            ]);
    
            $this->lastShownNumber = $random;
    
            // 🔹 Update the current position via API
            $updateResponse = callApi( [
                'NoOfWinnersShown' => $cpos + 2,'updateWinnersPosition'
            ]);
    
            if (!$updateResponse || ($updateResponse['success'] ?? false) !== true) {
                error_log("Failed to update winners position via API.");
                return;
            }
    
            // 🔹 Send the number to all connected clients
            foreach ($this->clients as $client) {
                $client->send($message);
            }
        });
    }
        
    public function onOpen(ConnectionInterface $client) {
        $this->clients->attach($client);
        echo "New connection: {$client->resourceId}\n";
    
        $client->send(json_encode([
            'type'    => 'status',
            'running' => $this->running,
            'paused'  => $this->paused,
            'all'     => $this->sentNumbers
        ]));
    
        if ($this->running === false && $this->refresh === false) {
            $this->startPostGameTimer(2);
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // Required by Ratchet, intentionally unused
    }

    public function onClose(ConnectionInterface $client) {
        $this->clients->detach($client);
        echo "Connection {$client->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $client, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $client->close();
    }
}

// Boot server
$loop = LoopFactory::create();
$apiUrl = "https://xb.xhawala.com/testcon.php";

$gameServer = new GameServer($loop, $apiUrl);

$webSocket = new WsServer($gameServer);
$httpServer = new HttpServer($webSocket);

$socket = new ReactServer('0.0.0.0:8999', $loop);
$server = new IoServer($httpServer, $socket, $loop);

echo "Server started on ws://localhost:8999\n";
$loop->run();
