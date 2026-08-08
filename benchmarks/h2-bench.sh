#!/usr/bin/env bash
#
# 同一台 native 服务、同一时刻的 HTTP/2（h2c）与 HTTP/1.1 同等条件对比。
#
# 为什么这是「同等条件」：native 运行时同一份代码同时支持 h1.1 与 h2c（h2c 前奏 /
# Upgrade 升级）。本脚本先对这个服务跑 h2load（h2c prior-knowledge），再跑 wrk
# （h1.1），两者面对的是完全相同的业务代码、机器状态与 12 字节载荷，
# 唯一变量是协议版本——因此 h2c 与 h1.1 的数字可直接比较。
#
# 用法：
#   bash benchmarks/h2-bench.sh [workers] [port] [h2_requests] [h1_duration] [connections] [threads]
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKERS="${1:-4}"
PORT="${2:-19301}"
H2_N="${3:-1000000}"
H1_DUR="${4:-15}"
CONNS="${5:-200}"
THREADS="${6:-4}"

command -v h2load >/dev/null 2>&1 || { echo "需要 h2load：brew install nghttp2"; exit 1; }
command -v wrk    >/dev/null 2>&1 || { echo "需要 wrk：brew install wrk"; exit 1; }

PHP_BIN="${PHP_BIN:-$(command -v php)}"

cleanup() { pkill -f "portable-server.php native ${PORT}" >/dev/null 2>&1; }
trap cleanup EXIT
cleanup

echo "启动 native 服务（workers=${WORKERS} port=${PORT}）…"
"$PHP_BIN" "${ROOT}/benchmarks/portable-server.php" native "$PORT" "$WORKERS" >/tmp/kode-h2.log 2>&1 &
for _ in $(seq 1 80); do nc -z 127.0.0.1 "$PORT" 2>/dev/null && break; sleep 0.1; done

echo
echo "########## NATIVE HTTP/2（h2c, prior-knowledge） ##########"
h2load -n "$H2_N" -c "$CONNS" -t "$THREADS" --warm-up-time 3 "http://127.0.0.1:${PORT}/" \
  | grep -E "Application protocol|finished in|req/s,|requests:|2xx|request     :|TTFB        :"

echo
echo "########## NATIVE HTTP/1.1（wrk） ##########"
wrk -t"$THREADS" -c"$CONNS" -d"${H1_DUR}s" --latency "http://127.0.0.1:${PORT}/" \
  | grep -E "Requests/sec|Latency|50%|90%|99%"

cleanup
