#!/usr/bin/env bash
#
# 混合负载长时压测（双向流控健壮性验证）：
# 用 h2c 以「大请求体 POST」施压，服务端既接收大请求体（recv 流控）又回送大响应（send 流控），
# 双向流控同时受压。各 worker 周期性向 STDERR 打印 PHP 内存峰值，压测后分析
# 「请求体缓冲 + 响应体缓冲 是否泄漏」。验证项：
#   1) 双向流控稳定：h2load 报错/超时即某方向窗口或连接级异常
#   2) 内存稳定：PHP 峰值应在热身期后趋于平稳，而非随时间无界增长
#   3) 吞吐与延迟在「大请求体 + 大响应」双向同时压下的表现
#
# 用法：
#   bash benchmarks/h2-mixed-stress.sh [workers] [port] [body_kb] [resp_kb] [duration_s] [connections] [threads] [max_streams] [mem_every]
#
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKERS="${1:-4}"
PORT="${2:-19311}"
BODY_KB="${3:-256}"
RESP_KB="${4:-256}"
DUR="${5:-60}"
CONNS="${6:-100}"
THREADS="${7:-4}"
MAX_STREAMS="${8:-100}"
MEM_EVERY="${9:-10000}"

command -v h2load >/dev/null 2>&1 || { echo "需要 h2load：brew install nghttp2"; exit 1; }
PHP_BIN="${PHP_BIN:-$(command -v php)}"

export KODE_MEM_EVERY="$MEM_EVERY"
export KODE_RESP_KB="$RESP_KB"

# 生成大请求体文件（POST 载荷），触发服务端 recv 窗口耗尽与 WINDOW_UPDATE 回发
BODY_FILE="/tmp/kode-mixed-body.bin"
BYTES=$((BODY_KB * 1024))
if command -v head >/dev/null 2>&1 && [ -r /dev/urandom ]; then
  head -c "$BYTES" /dev/urandom > "$BODY_FILE" 2>/dev/null || \
    head -c "$BYTES" /dev/zero  > "$BODY_FILE" 2>/dev/null || \
    printf 'KodePHP-mixed-body-payload-0123456789-' > "$BODY_FILE"
else
  printf 'KodePHP-mixed-body-payload-0123456789-' > "$BODY_FILE"
fi
echo "请求体文件：$BODY_FILE ($(wc -c < "$BODY_FILE") bytes)  响应体：${RESP_KB}KB"

LOG=/tmp/kode-mixed.log
: > "$LOG"

cleanup() {
  [ -n "${SERVER_PID:-}" ] && { kill "$SERVER_PID" 2>/dev/null; pkill -P "$SERVER_PID" 2>/dev/null; }
}
trap cleanup EXIT

echo "启动混合负载 native 服务（workers=${WORKERS} port=${PORT} body=${BODY_KB}KB resp=${RESP_KB}KB）…"
"$PHP_BIN" "${ROOT}/benchmarks/portable-server-post.php" native "$PORT" "$WORKERS" >"$LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 80); do nc -z 127.0.0.1 "$PORT" 2>/dev/null && break; sleep 0.1; done

echo
echo "########## NATIVE HTTP/2（h2c）混合负载长时压测：body=${BODY_KB}KB resp=${RESP_KB}KB dur=${DUR}s conns=${CONNS} max_streams=${MAX_STREAMS} ##########"
h2load -c "$CONNS" -t "$THREADS" -m "$MAX_STREAMS" --duration "$DUR" --warm-up-time 5 \
  -d "$BODY_FILE" "http://127.0.0.1:${PORT}/" \
  | tee /tmp/kode-mixed-h2.log

cleanup

echo
echo "########## 内存峰值观测（各 worker 周期性打印，取自 $LOG） ##########"
if grep -q "^MEM " "$LOG"; then
  grep "^MEM " "$LOG" | awk '
    {
      wid=$2; peak=$4; cur=$5;
      cnt[wid]++;
      if (!(wid in first_peak)) first_peak[wid]=peak;
      last_peak[wid]=peak;
      if (peak>maxp[wid]) maxp[wid]=peak;
      if (cnt[wid]==1 || peak<minp[wid]) minp[wid]=peak;
    }
    END {
      for (w in cnt) {
        growth = last_peak[w] - first_peak[w];
        printf "worker pid=%s 样本=%d 首次峰值=%d 末次峰值=%d 最小=%d 最大=%d 增长=%d bytes\n",
               w, cnt[w], first_peak[w], last_peak[w], minp[w], maxp[w], growth;
      }
    }'
  echo
  echo "（增长≈0 说明请求体/响应体缓冲双向均无泄漏；持续增长则提示泄漏）"
else
  echo "未捕获到 MEM 日志行，可能压测时长太短或 worker 未打印。可减小 KODE_MEM_EVERY。"
fi

echo
echo "########## 服务进程启动日志 ##########"
grep -E "mixed-load|worker started|Exception|Error|Fatal" "$LOG" | head -20 || true
