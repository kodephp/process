<?php

declare(strict_types=1);

namespace Kode\Process\Broadcast;

/**
 * 广播管理器
 * 
 * 用于向所有或特定客户端广播消息
 */
final class Broadcaster
{
    private array $connections = [];
    private array $groups = [];
    private array $uidMap = [];
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function register($connection, ?string $uid = null): void
    {
        $id = spl_object_id($connection);
        $this->connections[$id] = $connection;

        if ($uid !== null) {
            $this->uidMap[$uid] = $connection;
        }
    }

    public function unregister($connection): void
    {
        $id = spl_object_id($connection);
        unset($this->connections[$id]);

        $uid = array_search($connection, $this->uidMap, true);

        if ($uid !== false) {
            unset($this->uidMap[$uid]);
        }

        foreach ($this->groups as $group => &$members) {
            unset($members[$id]);
        }
    }

    public function joinGroup($connection, string $group): void
    {
        $id = spl_object_id($connection);

        if (!isset($this->groups[$group])) {
            $this->groups[$group] = [];
        }

        $this->groups[$group][$id] = $connection;
    }

    public function leaveGroup($connection, string $group): void
    {
        $id = spl_object_id($connection);
        unset($this->groups[$group][$id]);
    }

    public function leaveAllGroups($connection): void
    {
        $id = spl_object_id($connection);

        foreach ($this->groups as $group => &$members) {
            unset($members[$id]);
        }
    }

    public function broadcast(string $message): int
    {
        $count = 0;

        foreach ($this->connections as $connection) {
            try {
                $connection->send($message);
                $count++;
            } catch (\Throwable) {
            }
        }

        return $count;
    }

    public function broadcastToGroup(string $group, string $message): int
    {
        if (!isset($this->groups[$group])) {
            return 0;
        }

        $count = 0;

        foreach ($this->groups[$group] as $connection) {
            try {
                $connection->send($message);
                $count++;
            } catch (\Throwable) {
            }
        }

        return $count;
    }

    public function sendToUid(string $uid, string $message): bool
    {
        if (!isset($this->uidMap[$uid])) {
            return false;
        }

        try {
            $this->uidMap[$uid]->send($message);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function sendToUids(array $uids, string $message): int
    {
        $count = 0;

        foreach ($uids as $uid) {
            if ($this->sendToUid($uid, $message)) {
                $count++;
            }
        }

        return $count;
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    public function getGroupCount(): int
    {
        return count($this->groups);
    }

    public function getGroupMemberCount(string $group): int
    {
        return count($this->groups[$group] ?? []);
    }

    public function getUid(string $uid): mixed
    {
        return $this->uidMap[$uid] ?? null;
    }

    public function hasUid(string $uid): bool
    {
        return isset($this->uidMap[$uid]);
    }

    public function getGroups(): array
    {
        return array_keys($this->groups);
    }

    public function clear(): void
    {
        $this->connections = [];
        $this->groups = [];
        $this->uidMap = [];
    }
}
