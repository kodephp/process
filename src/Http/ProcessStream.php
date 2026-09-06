<?php

declare(strict_types=1);

namespace Kode\Process\Http;

use Psr\Http\Message\StreamInterface;

/**
 * 只读资源流（UploadedFile::getStream 配套最小实现）。
 *
 * @internal
 */
final class ProcessStream implements StreamInterface
{
    /** @param resource $resource */
    public function __construct(private $resource)
    {
    }

    public function __toString(): string
    {
        try {
            rewind($this->resource);

            return (string) stream_get_contents($this->resource);
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    public function detach()
    {
        $res = $this->resource;
        $this->resource = null;

        return $res;
    }

    public function getSize(): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }
        $stat = fstat($this->resource);

        return $stat !== false ? (int) $stat['size'] : null;
    }

    public function tell(): int
    {
        $pos = ftell($this->resource);
        if ($pos === false) {
            throw new \RuntimeException('无法定位流');
        }

        return $pos;
    }

    public function eof(): bool
    {
        return !is_resource($this->resource) || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        fseek($this->resource, $offset, $whence);
    }

    public function rewind(): void
    {
        rewind($this->resource);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('只读流');
    }

    public function isReadable(): bool
    {
        return is_resource($this->resource);
    }

    public function read($length): string
    {
        $data = fread($this->resource, $length);

        return $data === false ? '' : $data;
    }

    public function getContents(): string
    {
        $data = stream_get_contents($this->resource);

        return $data === false ? '' : $data;
    }

    public function getMetadata($key = null)
    {
        if (!is_resource($this->resource)) {
            return $key === null ? [] : null;
        }
        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}
