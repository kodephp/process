<?php

declare(strict_types=1);

/**
 * 示例: 标准响应格式
 *
 * 使用 code 替代 result 的统一响应格式
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Process\Response;

echo "=== 标准响应格式示例 ===\n\n";

// 成功响应
$success = Response::ok(['user_id' => 123, 'name' => 'John']);
echo "成功响应:\n";
echo $success->toJson(JSON_PRETTY_PRINT) . "\n\n";

// 错误响应
$error = Response::error('用户不存在', Response::CODE_NOT_FOUND);
echo "错误响应:\n";
echo $error->toJson(JSON_PRETTY_PRINT) . "\n\n";

// 带元数据的响应
$withMeta = Response::ok(['items' => [1, 2, 3]])
    ->withMeta('total', 100)
    ->withMeta('page', 1)
    ->withMeta('per_page', 10);
echo "带元数据的响应:\n";
echo $withMeta->toJson(JSON_PRETTY_PRINT) . "\n\n";

// 超时响应
$timeout = Response::timeout('请求超时');
echo "超时响应:\n";
echo $timeout->toJson(JSON_PRETTY_PRINT) . "\n\n";

// 验证响应
$invalid = Response::invalid('参数错误', ['field' => 'email', 'message' => '邮箱格式不正确']);
echo "验证错误响应:\n";
echo $invalid->toJson(JSON_PRETTY_PRINT) . "\n\n";

// 检查响应状态
echo "响应状态检查:\n";
echo "  isSuccess: " . ($success->isSuccess() ? 'true' : 'false') . "\n";
echo "  isError: " . ($error->isError() ? 'true' : 'false') . "\n";
echo "  code: " . $success->getCode() . "\n";
echo "  message: " . $success->getMessage() . "\n";
