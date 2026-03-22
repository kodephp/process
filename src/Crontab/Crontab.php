<?php

declare(strict_types=1);

namespace Kode\Process\Crontab;

final class Crontab
{
    private string $expression;
    private $callback;
    private ?int $id = null;
    private static int $idCounter = 0;
    private static array $instances = [];
    private ?int $lastRunTime = null;
    private int $nextRunTime = 0;
    private bool $enabled = true;

    public function __construct(string $expression, callable $callback)
    {
        $this->expression = $expression;
        $this->callback = $callback;
        $this->id = ++self::$idCounter;
        $this->calculateNextRunTime();
        self::$instances[$this->id] = $this;
    }

    public static function create(string $expression, callable $callback): self
    {
        return new self($expression, $callback);
    }

    public function tick(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $now = time();

        if ($now >= $this->nextRunTime) {
            $this->lastRunTime = $now;
            $this->calculateNextRunTime();
            
            try {
                ($this->callback)();
            } catch (\Throwable $e) {
                error_log("[Crontab] Error executing task #{$this->id}: " . $e->getMessage());
            }

            return true;
        }

        return false;
    }

    public function destroy(): void
    {
        $this->enabled = false;
        
        if ($this->id !== null) {
            unset(self::$instances[$this->id]);
        }
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function getLastRunTime(): ?int
    {
        return $this->lastRunTime;
    }

    public function getNextRunTime(): int
    {
        return $this->nextRunTime;
    }

    private function calculateNextRunTime(): void
    {
        $parts = preg_split('/\s+/', trim($this->expression));
        $partCount = count($parts);

        if ($partCount === 5) {
            array_unshift($parts, '*');
            $partCount = 6;
        }

        $now = time();
        $this->nextRunTime = $this->parseCronExpression($parts, $now);
    }

    private function parseCronExpression(array $parts, int $now): int
    {
        $sec = $this->parsePart($parts[0], 0, 59);
        $min = $this->parsePart($parts[1], 0, 59);
        $hour = $this->parsePart($parts[2], 0, 23);
        $day = $this->parsePart($parts[3], 1, 31);
        $month = $this->parsePart($parts[4], 1, 12);
        $weekday = $this->parsePart($parts[5] ?? '*', 0, 6);

        $current = getdate($now);
        $year = $current['year'];

        for ($i = 0; $i < 366 * 24 * 60 * 60; $i += 60) {
            $time = $now + $i;
            $date = getdate($time);

            if (!in_array($date['seconds'], $sec, true)) {
                continue;
            }

            if (!in_array($date['minutes'], $min, true)) {
                continue;
            }

            if (!in_array($date['hours'], $hour, true)) {
                continue;
            }

            if (!in_array($date['mon'], $month, true)) {
                continue;
            }

            if (!in_array($date['wday'], $weekday, true)) {
                continue;
            }

            if (!in_array($date['mday'], $day, true)) {
                continue;
            }

            return $time;
        }

        return $now + 86400;
    }

    private function parsePart(string $part, int $min, int $max): array
    {
        $result = [];

        if ($part === '*') {
            return range($min, $max);
        }

        $segments = explode(',', $part);

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if (strpos($segment, '/') !== false) {
                [$range, $step] = explode('/', $segment, 2);
                $step = (int)$step;

                if ($range === '*') {
                    $rangeMin = $min;
                    $rangeMax = $max;
                } elseif (strpos($range, '-') !== false) {
                    [$rangeMin, $rangeMax] = explode('-', $range, 2);
                    $rangeMin = (int)$rangeMin;
                    $rangeMax = (int)$rangeMax;
                } else {
                    $rangeMin = $rangeMax = (int)$range;
                }

                for ($i = $rangeMin; $i <= $rangeMax; $i += $step) {
                    if ($i >= $min && $i <= $max) {
                        $result[] = $i;
                    }
                }
            } elseif (strpos($segment, '-') !== false) {
                [$start, $end] = explode('-', $segment, 2);
                $start = (int)$start;
                $end = (int)$end;

                for ($i = $start; $i <= $end; $i++) {
                    if ($i >= $min && $i <= $max) {
                        $result[] = $i;
                    }
                }
            } else {
                $value = (int)$segment;
                
                if ($value >= $min && $value <= $max) {
                    $result[] = $value;
                }
            }
        }

        return array_unique($result);
    }

    public static function tickAll(): int
    {
        $executed = 0;

        foreach (self::$instances as $instance) {
            if ($instance->tick()) {
                $executed++;
            }
        }

        return $executed;
    }

    public static function getAll(): array
    {
        return self::$instances;
    }

    public static function count(): int
    {
        return count(self::$instances);
    }

    public static function destroyAll(): void
    {
        foreach (self::$instances as $instance) {
            $instance->destroy();
        }

        self::$instances = [];
    }

    public static function getNextRunTimes(): array
    {
        $times = [];

        foreach (self::$instances as $id => $instance) {
            $times[$id] = [
                'expression' => $instance->getExpression(),
                'next_run' => $instance->getNextRunTime(),
                'last_run' => $instance->getLastRunTime(),
                'enabled' => $instance->isEnabled()
            ];
        }

        return $times;
    }
}
