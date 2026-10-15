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
    /** @param resource|null $resource */
    public function __construct(private $resource)
    {
    }

    #[\Override]
    public function __toString(): string
    {
        try {
            rewind($this->resource);

            return (string) stream_get_contents($this->resource);
        } catch (\Throwable) {
            return '';
        }
    }

    #[\Override]
    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    #[\Override]
    public function detach()
    {
        $res = $this->resource;
        $this->resource = null;

        return $res;
    }

    #[\Override]
    public function getSize(): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }
        $stat = fstat($this->resource);

        return $stat !== false ? (int) $stat['size'] : null;
    }

    #[\Override]
    public function tell(): int
    {
        $pos = ftell($this->resource);
        if ($pos === false) {
            throw new \RuntimeException('无法定位流');
        }

        return $pos;
    }

    #[\Override]
    public function eof(): bool
    {
        return !is_resource($this->resource) || feof($this->resource);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek($offset, $whence = SEEK_SET): void
    {
        fseek($this->resource, $offset, $whence);
    }

    #[\Override]
    public function rewind(): void
    {
        rewind($this->resource);
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write($string): int
    {
        throw new \RuntimeException('只读流');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return is_resource($this->resource);
    }

    #[\Override]
    public function read($length): string
    {
        $data = fread($this->resource, $length);

        return $data === false ? '' : $data;
    }

    #[\Override]
    public function getContents(): string
    {
        $data = stream_get_contents($this->resource);

        return $data === false ? '' : $data;
    }

    #[\Override]
    public function getMetadata($key = null)
    {
        if (!is_resource($this->resource)) {
            return $key === null ? [] : null;
        }
        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}
