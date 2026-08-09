<?php

declare(strict_types=1);

namespace Kode\Process\Monitor;

final class FileMonitor
{
    /**
     * 递归扫描的最大深度，配合软链接跳过共同防止目录环导致的无限递归。
     */
    private const MAX_SCAN_DEPTH = 32;

    private array $watchDirs = [];
    private array $fileMtimes = [];
    private int $checkInterval = 1000000;
    private bool $running = false;
    private array $extensions = ['.php'];
    private array $excludeDirs = ['.git', '.svn', 'vendor', 'node_modules', '.trae'];
    private $onChangeCallback = null;
    private bool $daemonMode = false;
    private bool $debugMode = true;
    private int $lastCheckTime = 0;

    public function __construct(array $directories = [])
    {
        foreach ($directories as $dir) {
            $this->addWatchDir($dir);
        }
    }

    public function addWatchDir(string $directory): self
    {
        $realPath = realpath($directory);
        
        if ($realPath !== false && is_dir($realPath)) {
            $this->watchDirs[$realPath] = $realPath;
        }

        return $this;
    }

    public function removeWatchDir(string $directory): self
    {
        $realPath = realpath($directory);
        
        if ($realPath !== false) {
            unset($this->watchDirs[$realPath]);
        }

        return $this;
    }

    public function setExtensions(array $extensions): self
    {
        $this->extensions = $extensions;
        return $this;
    }

    public function addExtension(string $extension): self
    {
        if (!in_array($extension, $this->extensions, true)) {
            $this->extensions[] = $extension;
        }

        return $this;
    }

    public function setExcludeDirs(array $dirs): self
    {
        $this->excludeDirs = $dirs;
        return $this;
    }

    public function addExcludeDir(string $dir): self
    {
        if (!in_array($dir, $this->excludeDirs, true)) {
            $this->excludeDirs[] = $dir;
        }

        return $this;
    }

    public function setCheckInterval(int $microseconds): self
    {
        $this->checkInterval = $microseconds;
        return $this;
    }

    public function setOnChange(callable $callback): self
    {
        $this->onChangeCallback = $callback;
        return $this;
    }

    public function setDebugMode(bool $enabled): self
    {
        $this->debugMode = $enabled;
        return $this;
    }

    public function setDaemonMode(bool $enabled): self
    {
        $this->daemonMode = $enabled;
        return $this;
    }

    public function scan(): array
    {
        $files = [];

        foreach ($this->watchDirs as $dir) {
            $this->scanDirectory($dir, $files);
        }

        return $files;
    }

    private function scanDirectory(string $dir, array &$files, int $depth = 0): void
    {
        if ($depth > self::MAX_SCAN_DEPTH) {
            return;
        }

        $items = @scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                if (in_array($item, $this->excludeDirs, true)) {
                    continue;
                }

                $this->scanDirectory($path, $files, $depth + 1);
                continue;
            }

            if (is_file($path)) {
                $extension = '.' . pathinfo($path, PATHINFO_EXTENSION);

                if (in_array($extension, $this->extensions, true)) {
                    $mtime = @filemtime($path);

                    if ($mtime !== false) {
                        $files[$path] = $mtime;
                    }
                }
            }
        }
    }

    public function checkChanges(): array
    {
        $currentFiles = $this->scan();
        $changes = [
            'modified' => [],
            'added' => [],
            'deleted' => []
        ];

        foreach ($currentFiles as $file => $mtime) {
            if (!isset($this->fileMtimes[$file])) {
                $changes['added'][] = $file;
            } elseif ($this->fileMtimes[$file] !== $mtime) {
                $changes['modified'][] = $file;
            }
        }

        foreach ($this->fileMtimes as $file => $mtime) {
            if (!isset($currentFiles[$file])) {
                $changes['deleted'][] = $file;
            }
        }

        return $changes;
    }

    public function applyChanges(array $changes): void
    {
        foreach (['added', 'modified'] as $kind) {
            foreach ($changes[$kind] ?? [] as $file) {
                clearstatcache(true, $file);

                $mtime = @filemtime($file);

                if ($mtime === false) {
                    unset($this->fileMtimes[$file]);
                    continue;
                }

                $this->fileMtimes[$file] = $mtime;
            }
        }

        foreach ($changes['deleted'] ?? [] as $file) {
            unset($this->fileMtimes[$file]);
        }
    }

    public function hasChanges(): bool
    {
        $changes = $this->checkChanges();
        return !empty($changes['modified']) || !empty($changes['added']) || !empty($changes['deleted']);
    }

    public function start(): void
    {
        if ($this->daemonMode && !$this->debugMode) {
            return;
        }

        $this->fileMtimes = $this->scan();
        $this->running = true;

        echo "[FileMonitor] Started monitoring " . count($this->watchDirs) . " directories\n";

        while ($this->running) {
            usleep($this->checkInterval);
            $this->tick();
        }
    }

    public function tick(): bool
    {
        $this->lastCheckTime = time();

        $changes = $this->checkChanges();
        $hasChanges = !empty($changes['modified']) || !empty($changes['added']) || !empty($changes['deleted']);

        if (!$hasChanges) {
            return false;
        }

        if ($this->onChangeCallback !== null) {
            try {
                ($this->onChangeCallback)($changes);
            } catch (\Throwable $e) {
                error_log('FileMonitor 变更回调异常: ' . $e->getMessage());
            }
        }

        // 必须在回调之后推进基线，否则同一次变更会在每个 tick 反复上报，
        // 热重载场景下会退化为无限重启。
        $this->applyChanges($changes);

        return true;
    }

    public function getLastCheckTime(): int
    {
        return $this->lastCheckTime;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function getWatchDirs(): array
    {
        return array_values($this->watchDirs);
    }

    public function getFileCount(): int
    {
        return count($this->fileMtimes);
    }

    public function getTrackedFiles(): array
    {
        return array_keys($this->fileMtimes);
    }

    public function reset(): void
    {
        $this->fileMtimes = [];
        $this->lastCheckTime = 0;
    }

    public static function create(array $directories = []): self
    {
        return new self($directories);
    }

    public static function watch(array $directories, ?callable $onChange = null): self
    {
        $monitor = new self($directories);
        
        if ($onChange !== null) {
            $monitor->setOnChange($onChange);
        }

        return $monitor;
    }
}
