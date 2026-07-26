<?php

namespace AMIListener;

use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class AMIListener
{
    private array $listeners = [];
    private string $partialBuffer = '';
    private int $reconnectAttempts = 0;
    private int $maxReconnectAttempts = 15;
    private int $reconnectDelay = 5;

    private ?int $keepAliveTimerId = null;
    private ?int $heartbeatTimerId = null;
    private int $lastEventTime = 0;
    private bool $loggedIn = false;

    /** freexe timeout. */
    private int $heartbeatTimeoutSeconds = 120;

    private const MAX_BUFFER_SIZE = 512 * 1024; // 512 KB

    public function __construct(
        private readonly string $username,
        private readonly string $secret,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 5038,
    ) {
    }

    public function addListener(callable $function, string|array $event = ""): void
    {
        $this->listeners[] = ["function" => $function, "event" => $event];
    }

    // HeartbeatTimeout reconect
    public function setHeartbeatTimeout(int $seconds): void   {
        $this->heartbeatTimeoutSeconds = max(30, $seconds);
    }

    private function log(string $message): void   {
        echo date('[Y-m-d H:i:s] ') . $message . "\n";
    }

    private function resetReconnectAttempts(): void    {
        $this->reconnectAttempts = 0;
    }

    private function updateLastEventTime(): void    {
        $this->lastEventTime = time();
    }

    private function scheduleReconnect(AsyncTcpConnection $connection): void    {
        if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
            $this->log("Досягнуто максимальну кількість спроб підключення. Зупиняємо воркер.");
            Worker::stopAll();
            return;
        }
        $this->reconnectAttempts++;
        $this->log("Плануємо реконект, спроба {$this->reconnectAttempts}/{$this->maxReconnectAttempts} через {$this->reconnectDelay}с");
        $this->clearTimers();
        $connection->reconnect($this->reconnectDelay);
    }

    private function clearTimers(): void    {
        if ($this->keepAliveTimerId !== null) {
            Timer::del($this->keepAliveTimerId);
            $this->keepAliveTimerId = null;
        }
        if ($this->heartbeatTimerId !== null) {
            Timer::del($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }
    }

    private function startKeepAlive(TcpConnection $connection): void    {
        if ($this->keepAliveTimerId !== null) {
            Timer::del($this->keepAliveTimerId);
            $this->keepAliveTimerId = null;
        }

        $this->keepAliveTimerId = Timer::add(30, function () use ($connection) {
            if ($connection->getStatus() === TcpConnection::STATUS_ESTABLISHED && $this->loggedIn) {
                $connection->send("Action: Ping\r\n\r\n");
            }
        });
    }

    // Watchdog
    private function startHeartbeat(TcpConnection $connection): void    {
        if ($this->heartbeatTimerId !== null) {
            Timer::del($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }
        $this->heartbeatTimerId = Timer::add(60, function () use ($connection) {
            if ($this->loggedIn && (time() - $this->lastEventTime > $this->heartbeatTimeoutSeconds)) {
                $this->log("WARNING: Не було жодних подій більше {$this->heartbeatTimeoutSeconds} секунд -> примусовий реконект");
                $connection->close(); // onClose -> scheduleReconnect
            }
        });
    }

    private function processEventBlock(string $eventBlock, TcpConnection $connection): void    {
        $eventBlock = trim($eventBlock);
        if ($eventBlock === '') {
            return;
        }
        $lines = explode("\n", $eventBlock);
        $parameters = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(":", $line, 2);
            if (count($parts) === 2) {
                $parameters[trim($parts[0])] = trim($parts[1]);
            }
        }
        if (empty($parameters)) {
            return;
        }

        if (isset($parameters['Response'], $parameters['Ping']) && $parameters['Response'] === 'Success' && $parameters['Ping'] === 'Pong') {
            $this->updateLastEventTime();
            $this->log("<- Pong отриманий (keep-alive)");
            return;
        }

        if (isset($parameters['Response'])) {
            if ($parameters['Response'] === 'Success' && !isset($parameters['Ping'])) {
                $this->loggedIn = true;
                $this->updateLastEventTime();
                $this->log("Успішний логін в AMI");
                return;
            }
            if ($parameters['Response'] === 'Error') {
                $message = $parameters['Message'] ?? 'невідомо';

                if (stripos($message, 'Authentication failed') !== false) {
                    $this->log("Критична помилка логіну: невірний логін або пароль.");
                    $connection->close();
                    return;
                }
                $this->log("AMI Помилка (не критична): " . $message);
                return;
            }
        }

        foreach ($this->listeners as $listener) {
            $matches = $listener["event"] === ""
                || (isset($parameters["Event"]) && (
                    (is_array($listener["event"]) && in_array($parameters["Event"], $listener["event"], true))
                    || $parameters["Event"] === $listener["event"]
                ));

            if ($matches) {
                try {
                    call_user_func_array($listener["function"], [$parameters, $connection]);
                } catch (Throwable $e) {
                    $this->log("ПОМИЛКА в обробнику події: " . $e->getMessage());
                }
            }
        }
        $this->updateLastEventTime();
    }

    public function start(bool $autoReconnect = true): void    {
        $worker = new Worker();
        $worker->onWorkerStart = function () use ($autoReconnect) {
            $connection = new AsyncTcpConnection('tcp://' . $this->host . ':' . $this->port);

            $connection->onError = function (TcpConnection $connection, $code, $msg) use ($autoReconnect) {
                $this->log("Помилка з'єднання: $code - $msg");
                $this->loggedIn = false;
                if ($autoReconnect && $connection instanceof AsyncTcpConnection) {
                    $this->scheduleReconnect($connection);
                }
            };

            $connection->onConnect = function (TcpConnection $connection) {
                $this->resetReconnectAttempts();
                $this->log("З'єднання з Asterisk AMI відкрито");

                $loginData = "Action: Login\r\n" .
                             "Username: {$this->username}\r\n" .
                             "Secret: {$this->secret}\r\n" .
                             "Events: on\r\n\r\n";

                $connection->send($loginData);

                $this->startKeepAlive($connection);
                $this->startHeartbeat($connection);
            };

            $connection->onMessage = function (TcpConnection $connection, $data) {
                $this->partialBuffer .= $data;

                if (strlen($this->partialBuffer) > self::MAX_BUFFER_SIZE) {
                    $this->log("WARNING: Буфер перевищив " . self::MAX_BUFFER_SIZE . " байт — очищаємо");
                    $this->partialBuffer = '';
                    return;
                }

                $normalized = str_replace(["\r\n", "\r"], "\n", $this->partialBuffer);
                $events = explode("\n\n", $normalized);

                $this->partialBuffer = array_pop($events);

                foreach ($events as $eventBlock) {
                    if (trim($eventBlock) !== '') {
                        $this->processEventBlock($eventBlock, $connection);
                    }
                }
            };

            $connection->onClose = function (TcpConnection $connection) use ($autoReconnect) {
                $this->log("З'єднання з AMI закрито");
                $this->loggedIn = false;
                $this->clearTimers();
                if ($autoReconnect && $connection instanceof AsyncTcpConnection) {
                    $this->scheduleReconnect($connection);
                }
            };

            $connection->maxSendBufferSize = 2 * 1024 * 1024;   // 2 MB
            $connection->maxPackageSize    = 2 * 1024 * 1024;
            $connection->connect();
        };

        Worker::runAll();
    }

    public function addTimer(int|float $interval, callable $callable): void    {
        Timer::add($interval, $callable);
    }

    public static function getRecordingFile(
        int|string $id,
        string $defaultPath = "/var/spool/asterisk/monitor/",
        string $fileFormat = "wav",
    ): ?string {
        $y = date("Y", (int) round((float) $id));
        $m = date("m", (int) round((float) $id));
        $d = date("d", (int) round((float) $id));
        $path = $defaultPath . $y . "/" . $m . "/" . $d . "/*";
        $files = glob($path);

        if ($files !== false) {
            foreach ($files as $file) {
                if (substr($file, -strlen($id . "." . $fileFormat)) === $id . "." . $fileFormat) {
                    return file_get_contents($file) ?: null;
                }
            }
        }
        return null;
    }

    public function sendParameter(array $parameter): void    {}
       
}