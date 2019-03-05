<?php
/**
 * @copyright Copyright © 2014 Rollun LC (http://rollun.com/)
 * @license LICENSE.md New BSD License
 */

namespace rollun\callback\PidKiller;

use DateTime;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use rollun\callback\ConfigProvider;
use rollun\callback\Queues\Message;
use rollun\callback\Queues\QueueInterface;
use rollun\dic\InsideConstruct;
use RuntimeException;

class LinuxPidKiller implements PidKillerInterface
{
    const DEF_MAX_MESSAGE_COUNT = 1000;

    /** @var int */
    protected $maxMessageCount;

    /** @var QueueInterface */
    protected $pidKillerQueue;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(
        $maxMessageCount = null,
        QueueInterface $pidKillerQueue = null,
        LoggerInterface $logger = null
    )
    {
        InsideConstruct::setConstructParams([
            "logger" => LoggerInterface::class,
        ]);

        if (substr(php_uname(), 0, 7) == "Windows") {
            throw new RuntimeException('Pid killer does not work on Windows');
        }

        if ($maxMessageCount == null) {
            $maxMessageCount = self::DEF_MAX_MESSAGE_COUNT;
        }

        $this->maxMessageCount = $maxMessageCount;
    }

    public function getPidQueue()
    {
        return $this->pidKillerQueue;
    }

    /**
     * Push new message to queue
     * record array:
     * [
     *  'id' - process pid,
     *  'delaySecond' - delay seconds to become message visible in queue
     * ]
     *
     * @param $record
     */
    public function create($record)
    {
        if (!isset($record['pid'])) {
            throw new InvalidArgumentException("Field 'pid' is missing");
        }

        if (!isset($record['delaySeconds'])) {
            throw new InvalidArgumentException("Field 'delaySeconds' is missing");
        }

        $lstart = self::getPidStartTime($record['pid']);

        if (!$lstart) {
            throw new RuntimeException("Process with pid {$record['pid']} does not exist");
        }

        $this->pidKillerQueue->addMessage(Message::createInstance([
            'id' => self::generateId($record['pid'], $lstart),
            QueueClient::KEY_DELAY_SECOND => $record['delaySeconds'],
        ]));

        $this->logger->debug("PID-KILLER add pid {pid} to queue at {date}", [
            'date' => date('D d.m H:i:s'),
            'pid' => $record['pid'],
            'lstart' => $lstart,
        ]);
    }

    public static function getPidStartTime($pid)
    {
        $pids = LinuxPidKiller::ps();

        foreach ($pids as $pidInfo) {
            if ($pid == $pidInfo['pid']) {
                return $pidInfo['lstart'];
            }
        }

        return null;
    }

    public function __invoke()
    {
        $this->logger->debug("PID-KILLER start working at {date}", [
            'date' => date('D d.m H:i:s'),
        ]);

        $messageCount = 0;

        $pids = self::ps();
        while ($messageCount < $this->maxMessageCount && $queueMessage = $this->pidKillerQueue->getMessage()) {
            $messageCount++;
            $message = $queueMessage->getData();

            $this->logger->debug("PID-KILLER get message from queue", [
                'message' => $message,
            ]);

            foreach ($pids as $processInfo) {
                $id = $processInfo['id'];

                if ($id == $message['id']) {
                    [$pid] = explode('.', $message['id']);
                    $result = exec("kill -9 {$pid}");

                    if ($result) {
                        $this->logger->warning("PID-KILLER failed kill process message from queue", [
                            'message' => $message,
                            'result' => $result,
                        ]);
                    } else {
                        $this->pidKillerQueue->deleteMessage($queueMessage);
                        $this->logger->debug("PID-KILLER successfully kill process and delete message from queue", [
                            'message' => $message,
                        ]);
                    }
                }
            }
        }

        $this->logger->debug("PID-KILLER finish working at {date}", [
            'date' => date('D d.m H:i:s'),
        ]);
    }

    public static function generateId($pid, $lstart)
    {
        return "{$pid}.{$lstart}";
    }

    /**
     * Return result of linux ps command
     *
     * Result example
     *  [
     *      0 => [
     *          'id' => '1.123434123',
     *          'pid' => 1,
     *          'lstart' => 123434123,
     *      ],
     *  ]
     *
     * @return array
     */
    public static function ps()
    {
        exec('ps -eo pid,lstart,cmd | grep php', $pidsInfo);
        array_shift($pidsInfo);
        $pids = [];

        foreach ($pidsInfo as $pidInfo) {
            try {
                $pidInfo = trim($pidInfo);
                preg_match('/^(?<pid>\d+)\s+(?<lstart>\w{3}\s+\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4})/', $pidInfo, $matches);
                $timestamp = DateTime::createFromFormat('D M d H:i:s Y', $matches['lstart'])->getTimestamp();
                $pid = intval($matches['pid']);
                $pids[] = [
                    'id' => self::generateId($pid, $timestamp),
                    'pid' => $pid,
                    'lstart' => $timestamp,
                ];
            } catch (\Throwable $exception) {
                throw new RuntimeException("Has problem to parse process info: [$pidInfo][{$matches['pid']}][{$matches['lstart']}].", $exception->getCode(), $exception);
            }
        }

        return $pids;
    }

    /**
     * Return info for pid
     *      [
     *          'id' => '1.123434123',
     *          'pid' => 1,
     *          'lstart' => 123434123,
     *      ]
     *
     * @param int $pid
     * @return array|null
     */
    public static function pidInfo(int $pid)
    {
        $pidInfo = array_filter(self::ps(), function (array $pidInfo) use ($pid) {
            return $pidInfo['pid'] === $pid;
        });
        if (empty($pidInfo)) {
            return null;
        }
        return current($pidInfo);
    }

    public static function createIdFromPidAndTimestamp($pid, $timestamp = null)
    {
        $timestamp = $timestamp ?? time();

        return "{$pid}.{$timestamp}";
    }

    /**
     * @return array
     */
    public function __sleep()
    {
        return ["pidKillerQueue", "maxMessageCount"];
    }

    /**
     * @throws \ReflectionException
     */
    public function __wakeup()
    {
        InsideConstruct::initWakeup(["logger" => LoggerInterface::class]);
    }
}
