# Kode Process 开发文档

## 入门指南

### [特性说明](getting-started/feature.md)
- 纯 PHP 开发
- PHP 多进程支持
- TCP、UDP 支持
- 长连接支持
- 多协议支持
- 高并发支持
- 协程支持
- 分布式部署

### [简单示例](getting-started/simple-example.md)
- HTTP 服务示例
- WebSocket 服务示例
- TCP 服务示例
- 协程使用示例

### [安装指南](install.md)
- 环境要求
- 安装方式
- 常见问题

### [快速开始](quick-start.md)
- 第一个服务器
- 命令行参数
- 多协议支持

## 核心组件

### [Worker 详解](worker.md)
- Worker 属性
- 事件回调
- Connection 对象
- 多 Worker 示例

### [协议系统](protocol.md)
- 内置协议
- 自定义协议
- 协议注册

### [定时器](timer.md)
- 永久定时器
- 一次性定时器
- Cron 表达式

### [协程系统](coroutine.md)
- 协程驱动
- kode/fibers 驱动
- Swow 驱动
- Channel 通道
- WaitGroup 等待组

## 高级功能

### [Fiber 协程](fiber.md)
- 协程创建
- 批量处理
- 上下文传递

### [队列系统](queue.md)
- 任务处理器
- 任务类
- 队列消费者

### [Channel 分布式](channel.md)
- 服务端/客户端
- 订阅发布
- 分布式广播

### [GlobalData](global-data.md)
- 全局数据共享
- 原子操作
- 分布式锁

### [广播系统](broadcast.md)
- 群组管理
- 消息广播
- 分布式广播

### [信号处理](signal.md)
- 信号类型
- 优雅关闭
- 热重载

### [SSL/TLS](ssl.md)
- 证书配置
- HTTPS 服务
- WSS 服务

### [UDP 服务](udp.md)
- UDP 服务器
- DNS 代理
- 日志收集

## 性能与部署

### [性能压测对比](benchmark.md)
- 进程创建性能
- HTTP 服务性能
- WebSocket 性能
- 协程性能对比
- 综合评分

### [性能优化](performance.md)
- 压测工具
- 系统调优
- 代码优化

### [生产部署](deployment.md)
- 服务器配置
- Nginx 反向代理
- 监控告警

## 兼容与集成

### [Workerman 兼容](workerman-compat.md)
- 迁移指南
- API 对照表
- 注意事项

## 组合案例

### [实时聊天系统](case-chat.md)
- WebSocket + Channel + GlobalData
- 完整代码示例

### [任务队列系统](case-queue.md)
- Queue + Timer + API
- 完整代码示例

---

## 示例代码索引

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

---

## 快速链接

- [GitHub 仓库](https://github.com/kodephp/process)
- [Packagist](https://packagist.org/packages/kode/process)
- [问题反馈](https://github.com/kodephp/process/issues)
