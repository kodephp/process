<?php

declare(strict_types=1);

use Kode\Process\Async\HttpClient;
use Kode\Process\Async\Async;

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== HttpClient 异步 HTTP 客户端示例 ===\n\n";

$client = HttpClient::create('https://jsonplaceholder.typicode.com', 10.0);

// 1. GET 请求
echo "1. GET 请求\n";
$response = $client->get('/posts/1')->await();
echo "   状态码: {$response->getStatusCode()}\n";
echo "   耗时: " . round($response->getDuration() * 1000, 2) . "ms\n";

$data = $response->json();
echo "   标题: {$data['title']}\n";

// 2. POST 请求
echo "\n2. POST 请求\n";
$response = $client->post('/posts', [
    'title' => '测试文章',
    'body' => '这是文章内容',
    'userId' => 1,
], ['Content-Type' => 'application/json'])->await();

echo "   状态码: {$response->getStatusCode()}\n";
$data = $response->json();
echo "   创建的 ID: {$data['id']}\n";

// 3. PUT 请求
echo "\n3. PUT 请求\n";
$response = $client->put('/posts/1', [
    'title' => '更新后的标题',
    'body' => '更新后的内容',
    'userId' => 1,
], ['Content-Type' => 'application/json'])->await();

echo "   状态码: {$response->getStatusCode()}\n";

// 4. DELETE 请求
echo "\n4. DELETE 请求\n";
$response = $client->delete('/posts/1')->await();
echo "   状态码: {$response->getStatusCode()}\n";

// 5. 带查询参数的 GET 请求
echo "\n5. 带查询参数的 GET 请求\n";
$response = $client->get('/posts', ['userId' => 1, '_limit' => 3])->await();
$posts = $response->json();
echo "   获取到 " . count($posts) . " 篇文章\n";

// 6. JSON 请求
echo "\n6. JSON 请求\n";
$response = $client->json('POST', '/posts', [
    'title' => 'JSON 文章',
    'body' => 'JSON 内容',
    'userId' => 1,
])->await();

echo "   状态码: {$response->getStatusCode()}\n";

// 7. 并发请求
echo "\n7. 并发请求\n";
$requests = [
    'post1' => ['method' => 'GET', 'url' => '/posts/1'],
    'post2' => ['method' => 'GET', 'url' => '/posts/2'],
    'post3' => ['method' => 'GET', 'url' => '/posts/3'],
];

$results = $client->concurrent($requests, 3)->await();

foreach ($results as $key => $response) {
    $data = $response->json();
    echo "   {$key}: {$data['title']}\n";
}

// 8. 响应状态检查
echo "\n8. 响应状态检查\n";
$response = $client->get('/posts/1')->await();

echo "   isOk: " . ($response->isOk() ? 'true' : 'false') . "\n";
echo "   isSuccessful: " . ($response->isSuccessful() ? 'true' : 'false') . "\n";
echo "   isClientError: " . ($response->isClientError() ? 'true' : 'false') . "\n";

// 9. 自定义请求头
echo "\n9. 自定义请求头\n";
$client = HttpClient::create()
    ->withHeader('User-Agent', 'KodeProcess/1.0')
    ->withHeader('Accept', 'application/json')
    ->withTimeout(5.0);

$response = $client->get('https://jsonplaceholder.typicode.com/posts/1')->await();
echo "   状态码: {$response->getStatusCode()}\n";

echo "\n=== 示例完成 ===\n";
