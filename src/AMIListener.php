<?php
namespace AMIListener;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

/*
 Чангелог :)
 0.1 - перша версія
 0.2 - спроба поправити підвисання
 0.3 - чергова спроба поправити підвисання + введені таймери, таймаути, авторестарт при дісконекті
*/

class AMIListener
{
    private $host;
    private $port;
    private $username;
    private $secret;
    private $listeners = [];
    private $queue_send = [];

    private $partialBuffer = '';           // буфер для неповних подій
    private $reconnectAttempts = 0;
    private $maxReconnectAttempts = 12;
    private $reconnectDelay = 5;           // сек.
    private $keepAliveTimerId = null;
    private $loggedIn = false;

    public function __construct($username, $secret, $host = "127.0.0.1", $port = 5038){
        $this->username = $username;
        $this->secret = $secret;
        $this->host = $host;
        $this->port = $port;
    }

    public function addListener(callable $function, $event = ""){
        $this->listeners[] = ["function" => $function, "event" => $event];
    }

    private function call($parameter, &$connection){
        foreach ($this->listeners as $listener) {
            if ($listener["event"] === "") {
                call_user_func_array($listener["function"], [$parameter, &$connection]);
            } else if (isset($parameter["Event"]) && ((is_array($listener["event"]) && in_array($parameter["Event"], $listener["event"])) || $parameter["Event"] === $listener["event"])) {
                call_user_func_array($listener["function"], [$parameter, &$connection]);
            }
        }
    }

    public function addTimer($interval, $callable){
        Timer::add($interval, $callable);
    }

    private function log($message){
        echo date('[Y-m-d H:i:s] ') . $message . "\n";
    }

    private function resetReconnectAttempts(){
        $this->reconnectAttempts = 0;
    }

    private function scheduleReconnect($connection){
        if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
            $this->log("Досягнуто максимальну кількість спроб підключення ({$this->maxReconnectAttempts}). Зупиняємо воркер.");
            Worker::stopAll();
            return;
        }

        $this->reconnectAttempts++;
        $this->log("Planning reconnect, try {$this->reconnectAttempts}/{$this->maxReconnectAttempts} через {$this->reconnectDelay}с");
        // чистимо таймери перед новим реконектом
        Timer::delAll();

        Timer::add($this->reconnectDelay, function () use ($connection) {
            $connection->connect();
        }, null, false);
    }

    private function startKeepAlive($connection){
        if ($this->keepAliveTimerId !== null) {
            Timer::del($this->keepAliveTimerId);
        }

        $this->keepAliveTimerId = Timer::add(30, function () use ($connection) {
            if ($connection->getStatus() === TcpConnection::ESTABLISHED && $this->loggedIn) {
                $connection->send("Action: Ping\r\n\r\n");
                $this->log("Ping (keep-alive)");
            }
        }, null, true);
    }

    public function start($autoReconnect = true){
        $worker = new Worker();
        $worker->onWorkerStart = function () use ($autoReconnect) {
            $ws_connection = new AsyncTcpConnection('tcp://' . $this->host . ':' . $this->port);

            $ws_connection->onError = function (TcpConnection $connection, $code, $msg) use ($autoReconnect) {
                $this->log("Помилка з'єднання: $code - $msg");
                $this->loggedIn = false;
                if ($autoReconnect) {
                    $this->scheduleReconnect($connection);
                }
            };

            $ws_connection->onConnect = function (TcpConnection $connection) {
                $this->log("З'єднання відкрито");
                $this->resetReconnectAttempts();

                $connection->send("Action: Login\r\n");
                $connection->send("Username: " . $this->username . "\r\n");
                $connection->send("Secret: " . $this->secret . "\r\n\r\n");

                $this->startKeepAlive($connection);
            };

            $ws_connection->onMessage = function (TcpConnection $connection, $data) {
                // Додаємо до буфера
                $this->partialBuffer .= $data;

                // Нормалізуємо роздільники
                $normalized = str_replace("\r\n", "\n", $this->partialBuffer);
                $events = explode("\n\n", $normalized);

                // Останній елемент може бути неповним — зберігаємо його
                $this->partialBuffer = array_pop($events);

                foreach ($events as $eventBlock) {
                    if (trim($eventBlock) === '') continue;

                    $lines = explode("\n", $eventBlock);
                    $parameters = [];

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '') continue;

                        $parts = explode(":", $line, 2);
                        if (count($parts) === 2) {
                            $parameters[trim($parts[0])] = trim($parts[1]);
                        }
                    }

                    if (!empty($parameters)) {
                        // Перевіряємо відповідь на Login
                        if (isset($parameters['Response'])) {
                            if ($parameters['Response'] === 'Success') {
                                $this->loggedIn = true;
                                $this->log("Успішний логін в AMI");
                            } else if ($parameters['Response'] === 'Error') {
                                $this->log("Помилка логіну: " . ($parameters['Message'] ?? 'невідомо'));
                                $connection->close();
                                return;
                            }
                        }

                        $this->call($parameters, $connection);
                    }
                }
            };

            $ws_connection->onClose = function (TcpConnection $connection) use ($autoReconnect) {
                $this->log("З'єднання закрито");
                $this->loggedIn = false;

                if ($this->keepAliveTimerId !== null) {
                    Timer::del($this->keepAliveTimerId);
                    $this->keepAliveTimerId = null;
                }

                if ($autoReconnect) {
                    $this->scheduleReconnect($connection);
                }
            };

            $ws_connection->connect();
        };

        Worker::runAll();
    }

    public static function getRecordingFile($id, $defaultPath = "/var/spool/asterisk/monitor/", $fileFormat = "wav"){
        $y = date("Y", round($id));
        $m = date("m", round($id));
        $d = date("d", round($id));
        $path = $defaultPath . $y . "/" . $m . "/" . $d . "/*";
        $files = glob($path);
        if ($files !== false) {
            foreach ($files as $file) {
                if (substr($file, strlen($file) - 17 - strlen($fileFormat)) == $id . "." . $fileFormat) {
                    return file_get_contents($file);
                }
            }
        }
        return null;
    }

    public function sendParameter($parameter){
        array_push($this->queue_send, $parameter);
    }
}