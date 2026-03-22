<?php

declare(strict_types=1);

namespace Kode\Process\Monitor;

final class FileMonitor
{
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

    private function scanDirectory(string $dir, array &$files): void
    {
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                if (in_array($item, $this->excludeDirs, true)) {
                    continue;
                }

                $this->scanDirectory($path, $files);
                continue;
            }

            if (is_file($path)) {
                $extension = '.' . pathinfo($path, PATHINFO_EXTENSION);
                
                if (in_array($extension, $this->extensions, true)) {
                    $files[$path] = filemtime($path);
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
            } elseif ($this->fileMtimes[$file] < $mtime) {
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
        foreach ($changes['added'] as $file) {
            $this->fileMtimes[$file] = filemtime($file);
        }

        foreach ($changes['modified'] as $file) {
            $this->fileMtimes[$file] = filemtime($file);
        }

        foreach ($changes['deleted'] as $file) {
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
        $changes = $this->checkChanges();
        $hasChanges = !empty($changes['modified']) || !empty($changes['added']) || !empty($changes['deleted']);

        if ($hasChanges && $this->onChangeCallback !== null) {
            ($this->onChangeCallback)($changes);
        }

        return $hasChanges;
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

    public static function watch(array $directories, callable $onChange = null): self
    {
        $monitor = new self($directories);
        
        if ($onChange !== null) {
            $monitor->setOnChange($onChange);
        }

        return $monitor;
    }
}
