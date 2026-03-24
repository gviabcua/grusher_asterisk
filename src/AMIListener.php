<?php

namespace AMIListener;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class AMIListener
{
    private $host;
    private $port;
    private $username;
    private $secret;

    private $listeners = [];
    private $partialBuffer = '';
    private $reconnectAttempts = 0;
    private $maxReconnectAttempts = 15;
    private $reconnectDelay = 5;

    private $keepAliveTimerId = null;
    private $heartbeatTimerId = null;
    private $lastEventTime = 0;
    private $loggedIn = false;

    private const MAX_BUFFER_SIZE = 512 * 1024; // 512 KB

    public function __construct($username, $secret, $host = "127.0.0.1", $port = 5038)
    {
        $this->username = $username;
        $this->secret   = $secret;
        $this->host     = $host;
        $this->port     = $port;
    }

    public function addListener(callable $function, $event = "")
    {
        $this->listeners[] = ["function" => $function, "event" => $event];
    }

    private function log($message)
    {
        echo date('[Y-m-d H:i:s] ') . $message . "\n";
    }

    private function resetReconnectAttempts()
    {
        $this->reconnectAttempts = 0;
    }

    private function updateLastEventTime()
    {
        $this->lastEventTime = time();
    }

    private function scheduleReconnect($connection)
    {
        if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
            $this->log("Досягнуто максимальну кількість спроб підключення ({$this->maxReconnectAttempts}). Зупиняємо воркер.");
            Worker::stopAll();
            return;
        }

        $this->reconnectAttempts++;
        $this->log("Плануємо реконект, спроба {$this->reconnectAttempts}/{$this->maxReconnectAttempts} через {$this->reconnectDelay}с");

        Timer::delAll(); // очищаємо всі таймери перед новим підключенням

        Timer::add($this->reconnectDelay, function () use ($connection) {
            $connection->connect();
        }, null, false);
    }

    private function startKeepAlive($connection)
    {
        if ($this->keepAliveTimerId !== null) {
            Timer::del($this->keepAliveTimerId);
        }

        $this->keepAliveTimerId = Timer::add(30, function () use ($connection) {
            // Правильна перевірка статусу в Workerman 4.x
            if ($connection->getStatus() === TcpConnection::STATUS_ESTABLISH && $this->loggedIn) {
                $connection->send("Action: Ping\r\n\r\n");
                $this->log("→ Ping (keep-alive)");
            }
        }, null, true);
    }

    private function startHeartbeat($connection)
    {
        if ($this->heartbeatTimerId !== null) {
            Timer::del($this->heartbeatTimerId);
        }

        // Якщо більше 120 секунд не було жодної події — примусово перез’єднуємось
        $this->heartbeatTimerId = Timer::add(60, function () use ($connection) {
            if ($this->loggedIn && (time() - $this->lastEventTime > 120)) {
                $this->log("WARNING: Не було подій більше 2 хвилин → примусовий reconnect");
                $connection->close();
            }
        }, null, true);
    }

    private function processEventBlock($eventBlock, $connection)
    {
        $eventBlock = trim($eventBlock);
        if ($eventBlock === '') return;

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

        if (empty($parameters)) return;

        // Обробка відповіді на Login
        if (isset($parameters['Response'])) {
            if ($parameters['Response'] === 'Success') {
                $this->loggedIn = true;
                $this->log("Успішний логін в AMI");
                $this->updateLastEventTime();
            } elseif ($parameters['Response'] === 'Error') {
                $this->log("Помилка логіну: " . ($parameters['Message'] ?? 'невідомо'));
                $connection->close();
                return;
            }
        }

        // Викликаємо всі зареєстровані обробники
        foreach ($this->listeners as $listener) {
            if ($listener["event"] === "" ||
                (isset($parameters["Event"]) &&
                    ((is_array($listener["event"]) && in_array($parameters["Event"], $listener["event"])) ||
                     $parameters["Event"] === $listener["event"]))) {

                call_user_func_array($listener["function"], [$parameters, $connection]);
            }
        }

        $this->updateLastEventTime();
    }

    public function start($autoReconnect = true)
    {
        $worker = new Worker();

        $worker->onWorkerStart = function () use ($autoReconnect) {

            $connection = new AsyncTcpConnection('tcp://' . $this->host . ':' . $this->port);

            $connection->onError = function (TcpConnection $connection, $code, $msg) use ($autoReconnect) {
                $this->log("Помилка з'єднання: $code - $msg");
                $this->loggedIn = false;
                if ($autoReconnect) {
                    $this->scheduleReconnect($connection);
                }
            };

            $connection->onConnect = function (TcpConnection $connection) {
                $this->log("З'єднання з Asterisk AMI відкрито");
                $this->resetReconnectAttempts();
                $this->partialBuffer = '';
                $this->loggedIn = false;

                // Логін
                $connection->send("Action: Login\r\n");
                $connection->send("Username: " . $this->username . "\r\n");
                $connection->send("Secret: " . $this->secret . "\r\n\r\n");

                $this->startKeepAlive($connection);
                $this->startHeartbeat($connection);
            };

            $connection->onMessage = function (TcpConnection $connection, $data) {

                $this->partialBuffer .= $data;

                // Захист від переповнення буфера
                if (strlen($this->partialBuffer) > self::MAX_BUFFER_SIZE) {
                    $this->log("WARNING: Буфер перевищив " . self::MAX_BUFFER_SIZE . " байт — очищаємо");
                    $this->partialBuffer = '';
                    return;
                }

                // Нормалізуємо роздільники та розбиваємо на повні події
                $normalized = str_replace("\r\n", "\n", $this->partialBuffer);
                $events = explode("\n\n", $normalized);

                // Останній (можливо неповний) блок залишаємо в буфері
                $this->partialBuffer = array_pop($events);

                foreach ($events as $eventBlock) {
                    $this->processEventBlock($eventBlock, $connection);
                }
            };

            $connection->onClose = function (TcpConnection $connection) use ($autoReconnect) {
                $this->log("З'єднання з AMI закрито");
                $this->loggedIn = false;

                if ($this->keepAliveTimerId !== null) {
                    Timer::del($this->keepAliveTimerId);
                    $this->keepAliveTimerId = null;
                }
                if ($this->heartbeatTimerId !== null) {
                    Timer::del($this->heartbeatTimerId);
                    $this->heartbeatTimerId = null;
                }

                if ($autoReconnect) {
                    $this->scheduleReconnect($connection);
                }
            };

            // Додаткові налаштування Workerman
            $connection->maxSendBufferSize = 2 * 1024 * 1024;   // 2 MB
            $connection->maxPackageSize    = 2 * 1024 * 1024;

            $connection->connect();
        };

        Worker::runAll();
    }

    // ====================== Допоміжні методи ======================

    public function addTimer($interval, $callable)
    {
        Timer::add($interval, $callable);
    }

    public static function getRecordingFile($id, $defaultPath = "/var/spool/asterisk/monitor/", $fileFormat = "wav")
    {
        // твій старий метод без змін
        $y = date("Y", round($id));
        $m = date("m", round($id));
        $d = date("d", round($id));
        $path = $defaultPath . $y . "/" . $m . "/" . $d . "/*";
        $files = glob($path);

        if ($files !== false) {
            foreach ($files as $file) {
                if (substr($file, -strlen($id . "." . $fileFormat)) === $id . "." . $fileFormat) {
                    return file_get_contents($file);
                }
            }
        }
        return null;
    }

    public function sendParameter($parameter)
    {
        // якщо потрібна черга — можемо розширити пізніше
    }
}