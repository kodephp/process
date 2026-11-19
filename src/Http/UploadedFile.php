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

    /**
     * 回收未被 moveTo 认领的临时文件。
     *
     * 解析层为每个部件建一个临时文件；业务在类型/大小/MIME 校验阶段拒绝时往往直接返回，
     * 不会调用 moveTo——这些文件此前永久留在 sys_get_temp_dir()，大批量上传能把 /tmp 写满。
     * moveTo 成功后 path 已指向目标文件且 $moved 置位，这里不碰它。
     */
    public function __destruct()
    {
        if (!$this->moved && $this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    #[\Override]
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

    #[\Override]
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

    #[\Override]
    public function getSize(): ?int
    {
        return $this->size;
    }

    #[\Override]
    public function getError(): int
    {
        return $this->error;
    }

    #[\Override]
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    #[\Override]
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
