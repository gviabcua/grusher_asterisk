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
            $this->log("Досягнуто максимальну кількість спроб підключення. Зупиняємо воркер.");
            Worker::stopAll();
            return;
        }

        $this->reconnectAttempts++;
        $this->log("Плануємо реконект, спроба {$this->reconnectAttempts}/{$this->maxReconnectAttempts} через {$this->reconnectDelay}с");

        if ($this->keepAliveTimerId !== null) {
            Timer::del($this->keepAliveTimerId);
            $this->keepAliveTimerId = null;
        }
        if ($this->heartbeatTimerId !== null) {
            Timer::del($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }

        // ВАЖЛИВО: Використовуємо вбудований метод Workerman для реконекту
        $connection->reconnect($this->reconnectDelay);
    }

    private function startKeepAlive($connection)
    {
        // Видаляємо старий таймер, якщо він є
        if ($this->keepAliveTimerId !== null) {
            Timer::del($this->keepAliveTimerId);
            $this->keepAliveTimerId = null;
        }

        $this->keepAliveTimerId = Timer::add(30, function () use ($connection) {
            // Пінгуємо ТІЛЬКИ якщо з'єднання активне і ми залогінені
            if ($connection->getStatus() === TcpConnection::STATUS_ESTABLISH && $this->loggedIn) {
                $connection->send("Action: Ping\r\n\r\n");
                $this->log("-> Ping (keep-alive)");
            }
        }, null, true);
    }

    private function startHeartbeat($connection)
    {
        if ($this->heartbeatTimerId !== null) {
            Timer::del($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }

        $this->heartbeatTimerId = Timer::add(60, function () use ($connection) {
            if ($this->loggedIn && (time() - $this->lastEventTime > 120)) {
                $this->log("WARNING: Не було жодних подій більше 120 секунд -> примусовий реконект");
                $connection->close();   // це викличе onClose -> scheduleReconnect
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

        // ВАЖЛИВО: Спочатку перевіряємо чи це відповідь на Ping (Pong)
        if (isset($parameters['Response']) && $parameters['Response'] === 'Success' && isset($parameters['Ping']) && $parameters['Ping'] === 'Pong') {
            $this->updateLastEventTime();
            $this->log("<- Pong отриманий (keep-alive)");
            return;
        }

        // Обробка відповіді на Login та інших команд
        if (isset($parameters['Response'])) {
            if ($parameters['Response'] === 'Success' && !isset($parameters['Ping'])) {
                $this->loggedIn = true;
                $this->updateLastEventTime();
                $this->log("Успішний логін в AMI");
                return;
            } 
            elseif ($parameters['Response'] === 'Error') {
                $message = $parameters['Message'] ?? 'невідомо';
                
                // Якщо пароль невірний - це критично, зупиняємось
                if (stripos($message, 'Authentication failed') !== false) {
                    $this->log("Критична помилка логіну: невірний логін або пароль.");
                    $connection->close();
                    return;
                }
                
                // Якщо це синтаксична помилка (як Missing action), просто логуємо, але працюємо далі
                $this->log("AMI Помилка (не критична): " . $message);
                return;
            }
        }

        // Викликаємо всі зареєстровані обробники
        foreach ($this->listeners as $listener) {
            if ($listener["event"] === "" || (isset($parameters["Event"]) && ((is_array($listener["event"]) && in_array($parameters["Event"], $listener["event"])) || $parameters["Event"] === $listener["event"]))) {
                //call_user_func_array($listener["function"], [$parameters, $connection]);
                try {
                    //call_user_func($listener['function'], $parameters);
                    call_user_func_array($listener["function"], [$parameters, $connection]);
                } catch (\Throwable $e) {
                    $this->log("ПОМИЛКА в обробнику події: " . $e->getMessage());
                }
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

            $connection->onConnect = function ($connection) {
                $this->resetReconnectAttempts();
                $this->log("З'єднання з Asterisk AMI відкрито");

                // Пакет логіну має бути монолітним
                $loginData = "Action: Login\r\n" .
                             "Username: {$this->username}\r\n" .
                             "Secret: {$this->secret}\r\n" .
                             "Events: on\r\n\r\n";
                
                $connection->send($loginData);

                // Таймер пінгу (keep-alive)
                $this->keepAliveTimerId = Timer::add(30, function () use ($connection) {
                    // Відправляємо тільки якщо з'єднання ще живе
                    if ($connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                        $connection->send("Action: Ping\r\n\r\n");
                    }
                });
            };

            $connection->onMessage = function (TcpConnection $connection, $data) {
                $this->partialBuffer .= $data;

                if (strlen($this->partialBuffer) > self::MAX_BUFFER_SIZE) {
                    $this->log("WARNING: Буфер перевищив " . self::MAX_BUFFER_SIZE . " байт — очищаємо");
                    $this->partialBuffer = '';
                    return;
                }

                // Кращий спосіб розбору AMI (працює стабільніше)
                $normalized = str_replace(["\r\n", "\r"], "\n", $this->partialBuffer);
                $events = explode("\n\n", $normalized);

                // Останній (можливо неповний) блок залишаємо в буфері
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