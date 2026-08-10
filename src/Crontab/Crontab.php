<?php

declare(strict_types=1);

namespace Kode\Process\Crontab;

final class Crontab
{
    /** 月份名 → 序号，cron 通用扩展。 */
    private const MONTH_NAMES = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /** 星期名 → 序号（0=周日）。 */
    private const WEEKDAY_NAMES = [
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
    ];

    /** 向前搜索的时间上限（天）。超出即认为表达式在可预见未来不会命中。 */
    private const SEARCH_HORIZON_DAYS = 367;

    private string $expression;
    private $callback;
    private ?int $id = null;
    private static int $idCounter = 0;
    private static array $instances = [];
    private ?int $lastRunTime = null;
    private int $nextRunTime = 0;
    private bool $enabled = true;

    /** 解析后的字段集合，构造时求值一次，避免每次 tick 重复解析。 */
    private array $fields;

    public function __construct(string $expression, callable $callback)
    {
        $this->expression = $expression;
        $this->callback = $callback;
        $this->fields = $this->parseExpression($expression);
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
        // 必须从「下一秒」起搜索：以当前秒为起点会让 nextRunTime === now，
        // 于是同一秒内的每次 tick() 都满足 now >= nextRunTime 而重复执行任务
        // （事件循环 100ms 一跳 = 每秒执行 10 次）。
        $this->nextRunTime = $this->searchNext(time() + 1);
    }

    /**
     * 从 $from（含）起向后找到第一个匹配表达式的秒级时刻。
     *
     * 采用分层跳跃：字段不匹配时直接跳到下一个「该字段可能变化」的边界，
     * 而不是逐秒线性扫描（逐秒扫一年需 3155 万次迭代）。
     */
    private function searchNext(int $from): int
    {
        $f = $this->fields;
        $limit = $from + self::SEARCH_HORIZON_DAYS * 86400;
        $t = $from;

        while ($t < $limit) {
            $d = getdate($t);

            if (!in_array($d['mon'], $f['month'], true)) {
                $t = $this->advance($t, mktime(0, 0, 0, $d['mon'] + 1, 1, $d['year']));
                continue;
            }

            if (!$this->matchesDayOfMonthOrWeek($d, $f)) {
                $t = $this->advance($t, mktime(0, 0, 0, $d['mon'], $d['mday'] + 1, $d['year']));
                continue;
            }

            if (!in_array($d['hours'], $f['hour'], true)) {
                $t = $this->advance($t, mktime($d['hours'] + 1, 0, 0, $d['mon'], $d['mday'], $d['year']));
                continue;
            }

            if (!in_array($d['minutes'], $f['minute'], true)) {
                $t = $this->advance($t, mktime($d['hours'], $d['minutes'] + 1, 0, $d['mon'], $d['mday'], $d['year']));
                continue;
            }

            if (!in_array($d['seconds'], $f['second'], true)) {
                $t++;
                continue;
            }

            return $t;
        }

        // 语法合法但语义永不命中（如 2 月 30 日）。返回搜索地平线而非 now+86400：
        // 后者会让这类表达式变成「每天在随机时刻跑一次」的幽灵任务。
        return $limit;
    }

    /**
     * 前进到 $target，并保证时间戳严格单调递增。
     *
     * mktime 在夏令时切换点可能返回不大于当前值的时间戳，直接赋值会让
     * while 循环原地打转，这里兜底为至少 +1 秒。
     */
    private function advance(int $current, int|false $target): int
    {
        return ($target !== false && $target > $current) ? $target : $current + 1;
    }

    /**
     * 日期匹配。
     *
     * POSIX 规定：day-of-month 与 day-of-week 都被限定（非 `*`）时取**并集**，
     * 即「每月 13 号 或 每周五」；只要有一个是 `*` 则退化为只看另一个。
     */
    private function matchesDayOfMonthOrWeek(array $date, array $f): bool
    {
        $dayOk = in_array($date['mday'], $f['day'], true);
        $weekdayOk = in_array($date['wday'], $f['weekday'], true);

        if ($f['day_restricted'] && $f['weekday_restricted']) {
            return $dayOk || $weekdayOk;
        }

        return $dayOk && $weekdayOk;
    }

    /**
     * 解析整条表达式。
     *
     * 支持 5 段（分 时 日 月 周）与 6 段（秒 分 时 日 月 周）。5 段补秒为 `0`
     * 而非 `*`——标准 cron 语义是「在该分钟的第 0 秒执行」，补 `*` 会让任务
     * 落在构造时刻的随机秒上。
     *
     * @return array{second:int[],minute:int[],hour:int[],day:int[],month:int[],weekday:int[],day_restricted:bool,weekday_restricted:bool}
     */
    private function parseExpression(string $expression): array
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        $count = count($parts);

        if ($count === 5) {
            array_unshift($parts, '0');
            $count = 6;
        }

        if ($count !== 6 || $parts[0] === '') {
            throw new \InvalidArgumentException(
                "无效的 cron 表达式（需 5 或 6 个字段）: {$expression}"
            );
        }

        return [
            'second'             => $this->parsePart($parts[0], 0, 59, 'second'),
            'minute'             => $this->parsePart($parts[1], 0, 59, 'minute'),
            'hour'               => $this->parsePart($parts[2], 0, 23, 'hour'),
            'day'                => $this->parsePart($parts[3], 1, 31, 'day'),
            'month'              => $this->parsePart($parts[4], 1, 12, 'month'),
            'weekday'            => $this->parsePart($parts[5], 0, 7, 'weekday'),
            'day_restricted'     => $parts[3] !== '*',
            'weekday_restricted' => $parts[5] !== '*',
        ];
    }

    /**
     * 解析单个字段为其允许值集合。
     *
     * 非法输入一律抛异常而不是静默过滤：原实现把越界值过滤成空集合后，
     * 匹配恒为 false，任务被兜底成「每天跑一次」的幽灵任务，问题极难定位。
     *
     * @return int[]
     */
    private function parsePart(string $part, int $min, int $max, string $field): array
    {
        $part = trim($part);

        if ($part === '') {
            throw new \InvalidArgumentException("cron 字段 {$field} 为空");
        }

        $result = [];

        foreach (explode(',', $part) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                throw new \InvalidArgumentException("cron 字段 {$field} 含空的枚举项");
            }

            $step = 1;

            if (str_contains($segment, '/')) {
                [$segment, $stepText] = explode('/', $segment, 2);
                $stepText = trim($stepText);

                // step 为 0 或负数会让下面的 for 循环步进为 0 —— 原实现在此处
                // 无限循环并持续 append，直到耗尽内存。
                if (!preg_match('/^\d+$/', $stepText) || (int)$stepText < 1) {
                    throw new \InvalidArgumentException(
                        "cron 字段 {$field} 的步长必须为正整数，收到: /{$stepText}"
                    );
                }

                $step = (int)$stepText;
                $segment = trim($segment);
            }

            if ($segment === '*') {
                $rangeMin = $min;
                $rangeMax = $max;
            } elseif (str_contains($segment, '-')) {
                [$startText, $endText] = explode('-', $segment, 2);
                $rangeMin = $this->parseValue($startText, $min, $max, $field);
                $rangeMax = $this->parseValue($endText, $min, $max, $field);

                if ($rangeMin > $rangeMax) {
                    throw new \InvalidArgumentException(
                        "cron 字段 {$field} 的区间起点大于终点: {$segment}"
                    );
                }
            } else {
                $rangeMin = $this->parseValue($segment, $min, $max, $field);
                // 单值带步长（如 `5/10`）按 cron 惯例等价于 `5-max/10`
                $rangeMax = $step > 1 ? $max : $rangeMin;
            }

            for ($i = $rangeMin; $i <= $rangeMax; $i += $step) {
                // 周字段 7 与 0 同为周日，统一归一化为 getdate() 的 wday 口径
                $result[] = ($field === 'weekday' && $i === 7) ? 0 : $i;
            }
        }

        $result = array_values(array_unique($result));
        sort($result);

        if ($result === []) {
            throw new \InvalidArgumentException("cron 字段 {$field} 未解析出任何有效值: {$part}");
        }

        return $result;
    }

    /** 解析单个数值或名称（JAN/MON 等），越界即抛异常。 */
    private function parseValue(string $text, int $min, int $max, string $field): int
    {
        $text = trim($text);
        $lower = strtolower($text);

        if ($field === 'month' && isset(self::MONTH_NAMES[$lower])) {
            return self::MONTH_NAMES[$lower];
        }

        if ($field === 'weekday' && isset(self::WEEKDAY_NAMES[$lower])) {
            return self::WEEKDAY_NAMES[$lower];
        }

        if (!preg_match('/^\d+$/', $text)) {
            throw new \InvalidArgumentException("cron 字段 {$field} 含非法取值: {$text}");
        }

        $value = (int)$text;

        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(
                "cron 字段 {$field} 的取值 {$value} 超出范围 {$min}-{$max}"
            );
        }

        return $value;
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
