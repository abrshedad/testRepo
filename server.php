<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';
require "connection.php";        // Provides $conn (API wrapper)
require_once __DIR__ . '/bingoActions.php';

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
    protected $timer;
    protected $running = false;
    protected $paused = false;
    protected $refresh = false;
    protected $lastShownNumber = 0;
    protected $sentNumbers = [];
    protected $goodBingoTimer;
    protected $gameStartTimer;
    protected $conn;
    protected $gameSpeed = 3;
    protected $pendingPauses = [];

    public function __construct($loop, $dbConn) {
        $this->clients = new \SplObjectStorage;
        $this->loop = $loop;
        $this->conn = $dbConn;

        echo "GameServer initialized.\n";
    }

    private function startPeriodicRefresh($secs) {
        static $countdownTimer = null;
        static $remainingTime = 0;

        if ($countdownTimer) {
            $this->loop->cancelTimer($countdownTimer);
            $countdownTimer = null;
        }

        $remainingTime = $secs;

        $countdownTimer = $this->loop->addPeriodicTimer(1, function() use (&$remainingTime, &$countdownTimer) {
            if ($remainingTime <= 0) {
                echo "\n⏱ Countdown finished.\n";
                $this->loop->cancelTimer($countdownTimer);
                $countdownTimer = null;
                return;
            }

            $refreshMessage = json_encode([
                'type' => 'refresh',
                'message' => 'Betting is started... &#128523;',
                'remainingTime' => $remainingTime
            ]);

            foreach ($this->clients as $client) {
                $client->send($refreshMessage);
            }

            $remainingTime--;
        });
    }

    private function startPostGameTimer($secs) {
        if ($this->goodBingoTimer) {
            $this->loop->cancelTimer($this->goodBingoTimer);
        }

        echo "⏳ Post-game timer started...\n";

        $this->goodBingoTimer = $this->loop->addTimer($secs, function () {
            $success = afterGoodBingoAction($this->conn);

            if (!$success) {
                echo "🔁 Action failed — retrying in 5s\n";
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

    private function startBetting($secs) {
        $this->running = false;
        $this->paused = false;
        $this->refresh = true;
        $this->startPeriodicRefresh($secs);

        if ($this->gameStartTimer) {
            $this->loop->cancelTimer($this->gameStartTimer);
        }

        echo "⏳ Betting timer started...\n";

        $this->gameStartTimer = $this->loop->addTimer(40, function () {
            if (checkIfAllCartelasTaken($this->conn)) {
                echo "✅ All cartelas taken — starting game immediately\n";
                $this->startGameImmediately();
                return;
            }

            $this->startGameImmediately();
        });
    }

    private function startGameImmediately() {
        $numPlayers = checkNoOfPlayers($this->conn);
        if ($numPlayers <= 1) {
            echo "⚠️ Not enough players ($numPlayers) — restarting timer\n";
            $this->startBetting(40);
            return;
        }

        if ($this->gameStartTimer) {
            $this->loop->cancelTimer($this->gameStartTimer);
            $this->gameStartTimer = null;
        }

        $success = startTheGame($this->conn);
        if (!$success) {
            echo "🔁 Action failed — retrying in 5s\n";
            $this->loop->addTimer(5, function () {
                $this->startBetting(0);
            });
            return;
        }

        echo "📢 Bingo is now starting\n";

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

    public function checkBingo(array $phoneClientMap) {
        if (empty($phoneClientMap)) {
            $this->paused = false;
            $this->refresh = false;
            $resumeMsg = json_encode(['type' => 'resumed', 'message' => 'Game resumed']);
            foreach ($this->clients as $c) {
                $c->send($resumeMsg);
            }
            return;
        }

        $phoneCartelas = [];
        foreach ($phoneClientMap as $phone => $data) {
            $phoneCartelas[$phone] = $data['cartelas'];
        }

        $allResults = checkBingoWinners($this->conn, $phoneCartelas, $this->lastShownNumber);

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
        $hasWinner = $totalWinners > 0;

        $resultsToSend = [];
        foreach ($allResults as $phone => $cards) {
            foreach ($cards as $cartelaId => $cardData) {
                if (!$hasWinner || !empty($cardData['winner'])) {
                    $resultsToSend[$phone][$cartelaId] = $cardData;
                }
            }
        }

        foreach ($this->clients as $c) {
            $c->send(json_encode([
                'type' => 'cartelaDetail',
                'message' => $hasWinner ? 'Winning cartela(s)' : 'Cartela details',
                'cartelaDetail' => $resultsToSend
            ]));
        }

        foreach ($phoneClientMap as $phone => $data) {
            $client = $data['from'];
            $cartelas = $data['cartelas'];
            $hasWinningCartela = false;
            $discardedCartelas = [];

            foreach ($cartelas as $cartelaId) {
                if (!empty($allResults[$phone][$cartelaId]['winner'])) {
                    $hasWinningCartela = true;
                } else {
                    discardCartela($this->conn, $phone, $cartelaId);
                    $discardedCartelas[] = $cartelaId;
                }
            }

            if ($hasWinningCartela) {
                $client->send(json_encode([
                    'type' => 'congra',
                    'message' => "🎉 Congratulations! 🎉 You won!",
                    'winningCartelas' => array_keys(array_filter(
                        $allResults[$phone],
                        fn($c) => !empty($c['winner'])
                    ))
                ]));
            } else {
                $client->send(json_encode([
                    'type' => 'discarded',
                    'message' => "❌ None of your paused cards won.",
                    'discardedCartelas' => $discardedCartelas
                ]));
            }
        }

        if ($hasWinner) {
            $this->loop->addTimer(3, function () use ($winnerPhones, $totalWinners) {
                foreach ($winnerPhones as $phone) {
                    payWinner($this->conn, $phone, $totalWinners);
                }
                $this->startPostGameTimer(3);
            });
        } else {
            $this->loop->addTimer(5, function () {
                foreach ($this->clients as $c) {
                    $c->send(json_encode(['type' => 'resumed', 'message' => 'Game resumed']));
                }
            });
        }
    }

    public function startGame() {
        if ($this->timer) $this->loop->cancelTimer($this->timer);

        $this->timer = $this->loop->addPeriodicTimer($this->gameSpeed, function () {
            if (!$this->running || $this->paused) return;

            $winnerData = $this->conn->request("getWinner");
            if (!$winnerData || empty($winnerData['Winners'])) {
                echo "No winner record found.\n";
                return;
            }

            $res = $winnerData["Winners"];
            $cpos = (int)$winnerData["NoOfWinnersShown"];

            if ($cpos >= strlen($res)) {
                $this->loop->cancelTimer($this->timer);
                $this->timer = null;

                if (isNoCartelaTaken($this->conn)) {
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
            $this->lastShownNumber = $random;

            $this->conn->request("updateWinnerPos", ["newPos" => $cpos + 2]);

            $message = json_encode(['type' => 'number', 'value' => $random, 'all' => $this->sentNumbers]);
            foreach ($this->clients as $client) {
                $client->send($message);
            }
        });
    }

    public function onOpen(ConnectionInterface $clientConn) {
        $this->clients->attach($clientConn);
        echo "New connection: {$clientConn->resourceId}\n";

        $clientConn->send(json_encode([
            'type' => 'status',
            'running' => $this->running,
            'paused' => $this->paused,
            'all' => $this->sentNumbers
        ]));

        if (!$this->running && !$this->refresh) {
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
                if (!$phone || !$cartelaId || !$this->running) break;

                if ($this->paused) {
                    if (!isset($this->pendingPauses[$phone])) {
                        $this->pendingPauses[$phone] = ['from' => $from, 'cartelas' => []];
                    }
                    if (!in_array($cartelaId, $this->pendingPauses[$phone]['cartelas'], true)) {
                        $this->pendingPauses[$phone]['cartelas'][] = $cartelaId;
                    }
                    break;
                }

                if (!$this->paused) {
                    $this->paused = true;
                    $this->pendingPauses = [$phone => ['from' => $from, 'cartelas' => [$cartelaId]]];
                    $this->loop->addTimer(1, function () {
                        foreach ($this->clients as $client) {
                            $client->send(json_encode(['type' => 'paused', 'message' => 'Game paused for checking cards']));
                        }
                        $this->checkBingo($this->pendingPauses);
                        $this->pendingPauses = [];
                        $this->paused = false;
                    });
                }
                break;

            case 'resume':
                $this->paused = false;
                $this->refresh = false;
                $this->running = true;
                $this->startGame();
                break;

            case 'startGame':
            case 'restart':
                $this->running = true;
                $this->paused = false;
                $this->refresh = false;
                $this->sentNumbers = [];
                foreach ($this->clients as $c) $c->send(json_encode(['type' => $data['type']]));
                $this->startGame();
                break;

            case 'goodBingo':
            case 'multipleGoodBingo':
                $this->running = false;
                $this->paused = false;
                $this->refresh = false;
                foreach ($this->clients as $c) $c->send(json_encode(['type' => $data['type']]));
                $this->startPostGameTimer(2);
                break;

            case 'gameSpeed':
                if (isset($data['GameSpeed']) && is_numeric($data['GameSpeed'])) {
                    $this->gameSpeed = (float)$data['GameSpeed'];
                    if ($this->running && !$this->paused) {
                        if ($this->timer) $this->loop->cancelTimer($this->timer);
                        $this->startGame();
                    }
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $clientConn) {
        $this->clients->detach($clientConn);
        echo "Connection {$clientConn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $clientConn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $clientConn->close();
    }
}

// Boot up
$loop = LoopFactory::create();
$gameServer = new GameServer($loop, $conn);

$webSocket = new WsServer($gameServer);
$httpServer = new HttpServer($webSocket);
$socket = new ReactServer('0.0.0.0:8999', $loop);
$server = new IoServer($httpServer, $socket, $loop);

echo "Server started on port 8999\n";
$loop->run();
