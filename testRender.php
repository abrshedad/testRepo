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
    private ?string $winnersString = null;
    private int $currentPosition = 0;

    public function __construct($loop, $apiUrl) {
        $this->clients = new \SplObjectStorage;
        $this->loop = $loop;
        $this->testTimer = $loop;
        $this->apiUrl = $apiUrl;

        echo "GameServer initialized.\n";
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
        $this->winnersString = null;
        $this->currentPosition = 0;


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
    
        // 🔹 Load winners only once at game start
        if ($this->winnersString === null) {
            $apiResponse = callApi('getCurrentWinners');
    
            if (
                !$apiResponse ||
                !isset($apiResponse['success']) ||
                $apiResponse['success'] !== true
            ) {
                error_log("Failed to fetch winners from API.");
                return;
            }
    
            $this->winnersString  = $apiResponse['Winners'];
            $this->currentPosition = (int)$apiResponse['NoOfWinnersShown'];
        }
    
        $this->timer = $this->loop->addPeriodicTimer($this->gameSpeed, function () {
    
            if (!$this->running || $this->paused) {
                return;
            }
    
            // 🔹 If winners string is cleared, stop game
            if ($this->winnersString === null) {
                return;
            }
    
            if ($this->currentPosition >= strlen($this->winnersString)) {
                error_log("All numbers shown. Stopping.");
    
                if ($this->timer) {
                    $this->loop->cancelTimer($this->timer);
                    $this->timer = null;
                }
    
                // 🔹 Reset cached data
                $this->winnersString = null;
                $this->currentPosition = 0;
    
                // Optional post-game logic
                $cartelaResponse = callApi('isNoCartelaTaken');
                if (
                    $cartelaResponse &&
                    ($cartelaResponse['success'] ?? false) === true &&
                    $cartelaResponse['noCartelaTaken'] === 0
                ) {
                    $this->running = true;
                    $this->paused = false;
                    $this->refresh = false;
                    $this->sentNumbers = [];
    
                    $this->startPostGameTimer(5);
                } else {
                    // Delay execution by 30 seconds
                    sleep(30);  // PHP pauses execution for 30 seconds
                
                    $this->startPostGameTimer(5);
                }
    
                return;
            }
    
            // 🔹 Extract number
            $numStr = substr($this->winnersString, $this->currentPosition, 2);
            $random = (int)$numStr;
            $this->sentNumbers[] = $random;
    
            $message = json_encode([
                'type'  => 'number',
                'value' => $random,
                'all'   => $this->sentNumbers
            ]);
    
            $this->lastShownNumber = $random;
    
            // 🔹 Update position locally
            $this->currentPosition += 2;
    
            // 🔹 Persist position to API
            $updateResponse = callApi('updateWinnersPosition', [
                'NoOfWinnersShown' => $this->currentPosition
            ]);
    
            if (($updateResponse['success'] ?? false) !== true) {
                error_log("Failed to update winners position via API.");
                return;
            }
    
            // 🔹 Send to clients
            foreach ($this->clients as $client) {
                $client->send($message);
            }
        });
    }

    public function checkBingo(array $phoneClientMap)
    {
        if (empty($phoneClientMap)) {
            $this->paused  = false;
            $this->refresh = false;

            $resumeMsg = json_encode([
                'type' => 'resumed',
                'message' => 'Game resumed'
            ]);

            foreach ($this->clients as $c) {
                $c->send($resumeMsg);
            }
            return;
        }

        /* -------------------------------------------------
           1️⃣ Prepare phone → cartelas map
        ------------------------------------------------- */
        $phoneCartelas = [];
        foreach ($phoneClientMap as $phone => $data) {
            $phoneCartelas[$phone] = $data['cartelas'];
        }

        /* -------------------------------------------------
           2️⃣ Check bingo ONLY for requested cartelas
        ------------------------------------------------- */
        
        $allResults = callApi('checkBingoWinners', [
            'PhoneCartelas' => $phoneCartelas,
            'LastShownNumber'=> $this->lastShownNumber
        ]);
        
        if (!is_array($allResults)) {
            echo "All results is : ".$allResults;
            $allResults = [];
        }
        /*
          Expected result:
          [
            phone => [
              cartelaId => [ 'winner' => true/false, ... ]
            ]
          ]
        */

        /* -------------------------------------------------
           3️⃣ Collect winning phones
        ------------------------------------------------- */
        $winnerPhones = [];

        foreach ($allResults as $phone => $cards) {
            foreach ($cards as $cardData) {
                if (!empty($cardData['winner'])) {
                    $winnerPhones[] = $phone;
                    break;
                }
            }
        }

        $winnerPhones = array_unique($winnerPhones);
        $totalWinners = count($winnerPhones);
        $hasWinner    = $totalWinners > 0;

        /* -------------------------------------------------
           4️⃣ Filter cartelas to broadcast
        ------------------------------------------------- */
        $resultsToSend = [];

        foreach ($allResults as $phone => $cards) {
            foreach ($cards as $cartelaId => $cardData) {
                if (!$hasWinner || !empty($cardData['winner'])) {
                    $resultsToSend[$phone][$cartelaId] = $cardData;
                }
            }
        }

        /* -------------------------------------------------
           5️⃣ Broadcast cartela details to ALL clients
        ------------------------------------------------- */
        foreach ($this->clients as $c) {
            $c->send(json_encode([
                'type' => 'cartelaDetail',
                'message' => $hasWinner
                    ? 'Winning cartela(s)'
                    : 'Cartela details for all paused clients',
                'cartelaDetail' => $resultsToSend
            ]));
        }

        /* -------------------------------------------------
           6️⃣ Notify players & discard losing cartelas
        ------------------------------------------------- */
        foreach ($phoneClientMap as $phone => $data) {

            $client   = $data['from'];
            $cartelas = $data['cartelas'];

            $hasWinningCartela = false;

            $discardedCartelas = [];

            foreach ($cartelas as $cartelaId) {
                if (!empty($allResults[$phone][$cartelaId]['winner'])) {
                    $hasWinningCartela = true;
                } else {
                    // ❌ Discard ONLY this cartela
                    callApi('discardCartela',['Phone' => $phone,'CartelaId'=>$cartelaId]);
                    $discardedCartelas[] = $cartelaId;
                }
            }

            if ($hasWinningCartela) {

                $client->send(json_encode([
                    'type' => 'congra',
                    'message' => "🎉🎊 Congratulations! 🎊🎉\n\nYou Won the Bingo! 🏆",
                    'winningCartelas' => array_keys(array_filter(
                        $allResults[$phone],
                        function($c) {
                            return !empty($c['winner']);
                        }
                    ))
                ]));

            } else {

                $client->send(json_encode([
                    'type' => 'discarded',
                    'message' => "❌ Sorry, none of your paused cards won.",
                    'discardedCartelas' => $discardedCartelas
                ]));
            }

        }

        /* -------------------------------------------------
           7️⃣ Pay winners (once)
        ------------------------------------------------- */
        if ($hasWinner) {
            $this->loop->addTimer(3, function () use ($winnerPhones, $totalWinners) {

                foreach ($winnerPhones as $phone) {
                    error_log("Paying winner $phone | Total winners: $totalWinners");
                    //payWinner($this->conn, $phone, $totalWinners);
                    callApi('payWinner', ['Phone' => $phone,'TotalWinners' => $totalWinners]);
                }

                $this->startPostGameTimer(3);
            });
            return;
        }

        /* -------------------------------------------------
           8️⃣ Resume game if no winners
        ------------------------------------------------- */
        $this->loop->addTimer(5, function () {

            if ($this->timer && $this->timer != null) {
                $this->paused  = false;
                $this->refresh = false;
            } else if (callApi('isNoCartelaTaken')) {
                $this->running     = true;
                $this->paused      = false;
                $this->refresh     = false;
                $this->sentNumbers = [];

                $this->startPostGameTimer(3);
            }

            foreach ($this->clients as $c) {
                $c->send(json_encode([
                    'type' => 'resumed',
                    'message' => 'Game resumed'
                ]));
            }

            callApi('resetPlayingStatus');
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
        $data = json_decode($msg, true);
        if (!isset($data['type'])) return;

        switch ($data['type']) {
            case 'pause':
                $phone = $data['phone'] ?? null;
                $cartelaId = $data['cartelaId'] ?? null;

                // Ignore invalid requests
                if (!$phone || !$cartelaId || !$this->running) {
                    echo "Invalid pause request ignored\n";
                    break;
                }

                // Game already paused → collect during pause window
                if ($this->paused && isset($this->pauseTimer)) {

                    if (!isset($this->pendingPauses[$phone])) {
                        $this->pendingPauses[$phone] = [
                            'from' => $from,
                            'cartelas' => []
                        ];
                    }

                    // Avoid duplicate cartelas
                    if (!in_array($cartelaId, $this->pendingPauses[$phone]['cartelas'], true)) {
                        $this->pendingPauses[$phone]['cartelas'][] = $cartelaId;
                        echo "Added cartela {$cartelaId} for phone {$phone}\n";
                    }

                    break;
                }

                // Game running → start pause window
                if (!$this->paused) {
                    $this->paused = true;
                    $this->refresh = false;

                    $this->pendingPauses = [
                        $phone => [
                            'from' => $from,
                            'cartelas' => [$cartelaId]
                        ]
                    ];

                    echo "Pause requested by client {$from->resourceId}, phone {$phone}, cartela {$cartelaId}\n";

                    $this->pauseTimer = $this->loop->addTimer(1, function () {

                        // Notify all clients
                        $pauseMsg = json_encode([
                            'type' => 'paused',
                            'message' => 'Game paused for checking cards'
                        ]);

                        foreach ($this->clients as $client) {
                            $client->send($pauseMsg);
                        }

                        // Validate all collected cartelas
                        $this->checkBingo($this->pendingPauses);

                        // Cleanup
                        $this->pendingPauses = [];
                        unset($this->pauseTimer);

                        echo "CheckBingo completed for all phones/cartelas\n";
                    });
                } else {
                    echo "Late pause request ignored for phone {$phone}, cartela {$cartelaId}\n";
                }

                break;
            
            case 'resume':
                if ($this->running && $this->paused) {
                    $this->paused = false;
                    $this->refresh = false;
                    $resumeMsg = json_encode([
                        'type' => 'resumed',
                        'message' => 'Game resumed'
                    ]);
                    foreach ($this->clients as $c) {
                        $c->send($resumeMsg);
                    }
                    echo "Resumed by client {$from->resourceId}\n";
                } else {
                    $this->paused = false;
                    $this->refresh = false;
                    $this->running = true;
                    $this->startGame();
                    echo "Old game restarted\n";
                }
                break;

            case 'startGame':
            case 'restart':
                $this->running = true;
                $this->paused = false;
                $this->refresh = false;
                $this->sentNumbers = [];
                $startMessage = json_encode(['type' => $data['type']]);
                foreach ($this->clients as $c) {
                    $c->send($startMessage);
                }
                $this->startGame();
                break;

            case 'goodBingo':
            case 'multipleGoodBingo':
                $this->running = false;
                $this->paused = false;
                $this->refresh = false;
                $message = json_encode(['type' => $data['type']]);
                foreach ($this->clients as $c) {
                    $c->send($message);
                }

                $this->startPostGameTimer(2);
                break;

            case 'startBetting':
                $this->running = false;
                $this->paused = false;
                $this->refresh = true;
                break;

            case 'gameSpeed':
                if (isset($data['GameSpeed']) && is_numeric($data['GameSpeed'])) {
                    $this->gameSpeed = (float)$data['GameSpeed'];
                    echo "Game speed updated to: {$this->gameSpeed} seconds\n";

                    if ($this->running && !$this->paused) {
                        if ($this->timer) {
                            $this->loop->cancelTimer($this->timer);
                        }
                        $this->startGame();
                    }
                }
                break;
        }
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
