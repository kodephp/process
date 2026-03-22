# Kode Process 开发文档

## 目录

### 基础篇

1. [安装指南](install.md) - 环境要求、安装方式
2. [快速开始](quick-start.md) - 第一个服务器
3. [Worker 详解](worker.md) - 进程、事件回调、配置
4. [协议系统](protocol.md) - 内置协议、自定义协议
5. [定时器](timer.md) - Timer 使用详解

### 进阶篇

6. [Fiber 协程](fiber.md) - 协程编程
7. [队列系统](queue.md) - 任务队列、消费者
8. [Channel 分布式](channel.md) - 进程间通讯
9. [GlobalData](global-data.md) - 全局数据共享
10. [广播系统](broadcast.md) - 消息广播

### 高级篇

11. [信号处理](signal.md) - 进程信号
12. [IPC 通信](ipc.md) - 进程间通信
13. [SSL/TLS](ssl.md) - 安全连接
14. [UDP 服务](udp.md) - UDP 协议
15. [性能优化](performance.md) - 压测、调优

### 集成篇

16. [Workerman 兼容](workerman-compat.md) - 无缝迁移
17. [框架集成](integration.md) - Laravel、Symfony
18. [生产部署](deployment.md) - 部署、监控

## 示例代码

所有示例代码在 `examples/` 目录下：

| 编号 | 文件 | 说明 |
|------|------|------|
| 01 | simple-worker.php | 简单 Worker 示例 |
| 02 | signal-handling.php | 信号处理 |
| 03 | ipc-communication.php | IPC 通信 |
| 04 | process-monitor.php | 进程监控 |
| 05 | auto-scaling.php | 自动扩缩容 |
| 06 | daemon-mode.php | 守护进程模式 |
| 07 | http-server.php | HTTP 服务器 |
| 08 | integrate-fibers.php | Fiber 集成 |
| 09 | task-queue.php | 任务队列 |
| 10 | simple-server.php | 简单服务器 |
| 11 | queue-consumer.php | 队列消费者 |
| 12 | job-class.php | 任务类定义 |
| 13 | response-format.php | 响应格式 |
| 14 | benchmark.php | 性能测试 |
| 15 | production-queue.php | 生产队列 |
| 16 | workerman-compat.php | Workerman 兼容 |
| 18 | benchmark-compare.php | 性能对比 |
| 19 | async-event-emitter.php | 异步事件 |
| 20 | async-promise.php | Promise |
| 21 | async-tools.php | 异步工具 |
| 22 | async-http-client.php | HTTP 客户端 |
| 23 | timer.php | 定时器 |
| 24 | channel-server.php | Channel 服务端 |
| 25 | channel-client.php | Channel 客户端 |
| 26 | global-data-server.php | GlobalData 服务端 |
| 27 | global-data-client.php | GlobalData 客户端 |
| 28 | broadcast-chat.php | 广播聊天 |
| 29 | ssl-server.php | SSL 服务器 |
| 30 | udp-server.php | UDP 服务器 |
| 31 | auth-timeout.php | 认证超时 |
| 32 | websocket-chat.php | WebSocket 聊天 |
| 33 | fiber-websocket.php | Fiber WebSocket |

## 快速链接

- [GitHub 仓库](https://github.com/kodephp/process)
- [Packagist](https://packagist.org/packages/kode/process)
- [问题反馈](https://github.com/kodephp/process/issues)
