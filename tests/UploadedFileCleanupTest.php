<?php

declare(strict_types=1);

namespace Kode\Process\Tests;

use Kode\Process\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * 上传临时文件的回收。
 *
 * 解析层为每个 multipart 部件建一个临时文件；业务在类型/大小/MIME 校验阶段拒绝时
 * 通常直接返回，不会调 moveTo——这些文件此前永久留在 /tmp，批量上传能把磁盘写满。
 */
final class UploadedFileCleanupTest extends TestCase
{
    public function testUnclaimedTempFileIsRemovedOnDestruct(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kode_up_test_');
        self::assertIsString($tmp);
        file_put_contents($tmp, 'payload');

        $file = new UploadedFile($tmp, 7);
        unset($file);

        self::assertFileDoesNotExist($tmp, '未 moveTo 的临时文件必须回收');
    }

    public function testMovedFileSurvivesDestruct(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kode_up_test_');
        self::assertIsString($tmp);
        file_put_contents($tmp, 'payload');
        $target = sys_get_temp_dir() . '/kode_up_target_' . bin2hex(random_bytes(6));

        try {
            $file = new UploadedFile($tmp, 7);
            $file->moveTo($target);
            unset($file);

            self::assertFileExists($target, 'moveTo 之后临时文件归业务，析构不得删除目标');
            self::assertSame('payload', file_get_contents($target));
            self::assertFileDoesNotExist($tmp);
        } finally {
            @unlink($target);
        }
    }
}
