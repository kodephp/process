# 生产部署

本文档介绍如何将 Kode Process 部署到生产环境。

## 服务器要求

### 硬件要求

| 项目 | 最低配置 | 推荐配置 |
|------|----------|----------|
| CPU | 2 核心 | 4+ 核心 |
| 内存 | 2GB | 8GB+ |
| 存储 | 10GB | 50GB+ SSD |
| 网络 | 100Mbps | 1Gbps |

### 软件要求

| 软件 | 版本 |
|------|------|
| PHP | 8.1+ |
| Composer | 2.0+ |
| 扩展 | pcntl, posix, sockets |
| 可选扩展 | opcache, redis, pdo |

## 安装部署

### 1. 安装 PHP

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install php8.3-cli php8.3-fpm php8.3-mysql php8.3-redis php8.3-curl

# CentOS/RHEL
sudo yum install php83-php-cli php83-php-fpm php83-php-mysqlnd php83-php-redis
```

### 2. 安装扩展

```bash
# 必需扩展
sudo apt install php8.3-pcntl php8.3-posix php8.3-sockets

# 可选扩展
sudo apt install php8.3-opcache php8.3-redis php8.3-pdo php8.3-mysql
```

### 3. 安装项目

```bash
# 创建目录
sudo mkdir -p /var/www/kode-process
cd /var/www/kode-process

# 克隆代码
git clone https://github.com/kodephp/process.git .

# 安装依赖
composer install --no-dev --optimize-autoloader
```

### 4. 配置 PHP

```ini
; /etc/php/8.3/cli/php.ini

; 基础配置
memory_limit = 512M
max_execution_time = 0
max_input_time = 0

; OPcache 配置
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
opcache.save_comments = 0

; JIT 配置 (PHP 8.0+)
opcache.jit_buffer_size = 256M
opcache.jit = 1255
```

### 5. 系统优化

```bash
# /etc/sysctl.conf

# 网络优化
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_tw_recycle = 1
net.ipv4.tcp_fin_timeout = 30
net.ipv4.tcp_keepalive_time = 60

# 文件描述符
fs.file-max = 2097152

# 应用配置
sudo sysctl -p
```

```bash
# /etc/security/limits.conf

* soft nofile 65535
* hard nofile 65535
root soft nofile 65535
root hard nofile 65535
```

## 启动服务

### 创建启动脚本

```bash
#!/bin/bash
# /var/www/kode-process/start.sh

cd /var/www/kode-process

# 启动 Channel 服务
php channel-server.php start -d

# 启动 GlobalData 服务
php global-data-server.php start -d

# 等待基础服务启动
sleep 2

# 启动业务服务
php http-server.php start -d
php websocket-server.php start -d

echo "所有服务已启动"
```

```bash
#!/bin/bash
# /var/www/kode-process/stop.sh

cd /var/www/kode-process

php http-server.php stop
php websocket-server.php stop
php channel-server.php stop
php global-data-server.php stop

echo "所有服务已停止"
```

```bash
chmod +x start.sh stop.sh
```

### Systemd 服务

```ini
# /etc/systemd/system/kode-process.service

[Unit]
Description=Kode Process Server
After=network.target

[Service]
Type=forking
User=www-data
Group=www-data
WorkingDirectory=/var/www/kode-process
ExecStart=/var/www/kode-process/start.sh
ExecStop=/var/www/kode-process/stop.sh
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
# 启用服务
sudo systemctl daemon-reload
sudo systemctl enable kode-process
sudo systemctl start kode-process

# 查看状态
sudo systemctl status kode-process
```

## Nginx 反向代理

### HTTP 反向代理

```nginx
# /etc/nginx/sites-available/kode-process

upstream kode_http {
    server 127.0.0.1:8080;
    keepalive 64;
}

server {
    listen 80;
    server_name example.com;
    
    location / {
        proxy_pass http://kode_http;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Connection "";
        proxy_connect_timeout 60s;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }
}
```

### WebSocket 反向代理

```nginx
upstream kode_ws {
    server 127.0.0.1:8081;
}

server {
    listen 80;
    server_name ws.example.com;
    
    location / {
        proxy_pass http://kode_ws;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 3600s;
    }
}
```

### SSL 配置

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;
    
    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    
    location / {
        proxy_pass http://kode_http;
        # ... 其他配置
    }
}

# HTTP 重定向到 HTTPS
server {
    listen 80;
    server_name example.com;
    return 301 https://$server_name$request_uri;
}
```

## 监控告警

### 进程监控

```bash
#!/bin/bash
# /var/www/kode-process/monitor.sh

# 检查进程是否运行
check_process() {
    local name=$1
    local count=$(pgrep -f "$name" | wc -l)
    
    if [ $count -eq 0 ]; then
        echo "[$(date)] $name 进程不存在，尝试重启..."
        cd /var/www/kode-process
        php "$name" start -d
        # 发送告警
        curl -X POST "https://api.example.com/alert" \
            -d "message=$name 进程已重启"
    fi
}

check_process "http-server.php"
check_process "websocket-server.php"
check_process "channel-server.php"
```

```bash
# 添加到 crontab
* * * * * /var/www/kode-process/monitor.sh >> /var/log/kode-monitor.log 2>&1
```

### 日志管理

```bash
# /etc/logrotate.d/kode-process

/var/www/kode-process/runtime/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0644 www-data www-data
}
```

## 性能调优

### Worker 数量

```php
// 根据 CPU 核心数设置
$worker->count = cpu_get_count();

// 或手动设置
$worker->count = 4;  // 4 核 CPU
```

### 内存限制

```php
// 设置最大请求数后重启
$worker->maxRequests = 10000;

// 设置内存限制
$worker->maxMemory = 128 * 1024 * 1024;  // 128MB
```

### 连接限制

```php
// 设置最大连接数
$worker->maxConnections = 10000;

// 设置连接超时
$worker->onConnect = function ($connection) {
    $connection->timeout = 60;  // 60 秒
};
```

## 故障排查

### 常见问题

1. **端口被占用**
```bash
# 查看端口占用
netstat -tlnp | grep 8080

# 杀死进程
kill -9 <pid>
```

2. **权限问题**
```bash
# 修改文件所有者
chown -R www-data:www-data /var/www/kode-process

# 修改权限
chmod -R 755 /var/www/kode-process
```

3. **内存泄漏**
```bash
# 查看内存使用
ps aux | grep php

# 重启服务
kill -TERM $PID && php http_server.php
```

### 日志查看

```bash
# 查看运行日志
tail -f /var/www/kode-process/runtime/server.log

# 查看错误日志
tail -f /var/www/kode-process/runtime/error.log

# 查看系统日志
tail -f /var/log/syslog | grep kode
```

## 备份恢复

### 数据备份

```bash
#!/bin/bash
# /var/www/kode-process/backup.sh

BACKUP_DIR="/backup/kode-process"
DATE=$(date +%Y%m%d_%H%M%S)

# 创建备份目录
mkdir -p $BACKUP_DIR

# 备份配置
tar -czf $BACKUP_DIR/config_$DATE.tar.gz \
    /var/www/kode-process/config \
    /var/www/kode-process/.env

# 备份数据（如有）
# mysqldump -u user -p database > $BACKUP_DIR/db_$DATE.sql

# 清理旧备份（保留 7 天）
find $BACKUP_DIR -type f -mtime +7 -delete
```

### 定时备份

```bash
# 每天凌晨 2 点备份
0 2 * * * /var/www/kode-process/backup.sh
```

## 安全加固

### 防火墙配置

```bash
# 只开放必要端口
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### PHP 安全配置

```ini
; 禁用危险函数
disable_functions = exec,passthru,shell_exec,system,proc_open,popen

; 隐藏 PHP 版本
expose_php = Off

; 限制文件上传
upload_max_filesize = 10M
post_max_size = 10M
```

### 代码安全

```php
// 过滤用户输入
$input = htmlspecialchars($request['input']);

// 使用预处理语句
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);

// 验证请求来源
if ($request['headers']['X-Requested-With'] !== 'XMLHttpRequest') {
    return Response::error('Invalid request', 403);
}
```
