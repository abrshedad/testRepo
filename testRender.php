<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require __DIR__ . '/vendor/autoload.php';
requier __DIR__ . '/testActivity.php';

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

    public function __construct($loop, $apiUrl) {
        $this->clients = new \SplObjectStorage;
        $this->loop = $loop;
        $this->testTimer = $loop;
        $this->apiUrl = $apiUrl;

        // Start polling every 5 seconds
        $this->loop->addPeriodicTimer(5, function () {
            echo "fetch data\n";
            //$this->fetchDataFromApi();
        });

        $this->loop->addPeriodicTimer(5,function(){
            echo "fetch status from testActivity";
            $status = getDetail();
            echo $status."\n";
            foreach ($this->clients as $client) {
                $client->send($status);
            }
        });
    }

    /** 🔄 Fetch data from testcon.php API */
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
        curl_close($ch);

        if ($response === false) {
            echo "CURL ERROR: " . curl_error($ch) . "\n";
            return;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['latest_rows'])) {
            echo "Invalid JSON from API: " . $response . "\n";
            return;
        }

        foreach ($data['latest_rows'] as $row) {
            $id = $row['No'];
            $num = $row['UserName']; // adjust field if different

            echo "\n".$id." ::: ".$num."\n";

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

    /** 🔗 WebSocket Handlers */
    public function onOpen(ConnectionInterface $client) {
        $this->clients->attach($client);
        echo "New connection: {$client->resourceId}\n";

        $client->send(json_encode([
            'type' => 'status',
            'all' => $this->sentNumbers
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // Not used, but required by Ratchet
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

// Boot up WebSocket server
$loop = LoopFactory::create();
$apiUrl = "https://xb.xhawala.com/testcon.php"; // your API endpoint
$gameServer = new GameServer($loop, $apiUrl);

$webSocket = new WsServer($gameServer);
$httpServer = new HttpServer($webSocket);

$socket = new ReactServer('0.0.0.0:8999', $loop);
$server = new IoServer($httpServer, $socket, $loop);

echo "Server started on ws://localhost:8999\n";
$loop->run();
