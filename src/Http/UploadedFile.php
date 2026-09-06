<?php

declare(strict_types=1);

namespace Kode\Process\Http;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 已上传文件（Native 运行时 multipart 解析产物）。
 *
 * 文件内容落临时文件（moveTo 直接 rename，零拷贝）；字符串 small body 也可直接持有。
 */
final class UploadedFile implements \Psr\Http\Message\UploadedFileInterface
{
    private bool $moved = false;

    /**
     * @param string $path 临时文件路径（moveTo 前必须存在）
     */
    public function __construct(
        private string $path,
        private readonly int $size,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new \RuntimeException('文件已移动');
        }

        $res = @fopen($this->path, 'rb');
        if ($res === false) {
            throw new \RuntimeException('无法读取上传临时文件');
        }

        return new ProcessStream($res);
    }

    public function moveTo($targetPath): void
    {
        if ($this->moved) {
            throw new \RuntimeException('文件已移动');
        }
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('上传失败，无法移动');
        }

        $dir = dirname((string) $targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // 同盘 rename，跨盘回退拷贝。
        if (!@rename($this->path, (string) $targetPath)) {
            if (!@copy($this->path, (string) $targetPath)) {
                throw new \RuntimeException('移动上传文件失败');
            }
            @unlink($this->path);
        }
        $this->moved = true;
        $this->path = (string) $targetPath;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
