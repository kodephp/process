#!/usr/bin/env bash
#
# 大响应长时压测（健壮性验证）：在固定大响应体下以 h2c 持续施压，
# 同时由各 worker 周期性向 STDERR 打印 PHP 内存峰值（见 portable-server-large），
# 压测后从日志分析「大响应缓冲是否泄漏」。验证项：
#   1) 流控稳定：h2load 报错/超时即流控或连接级异常
#   2) 内存稳定：PHP 峰值应在热身期后趋于平稳，而非随时间无界增长
#   3) 吞吐与延迟在「大响应」下的表现
#
# 用法：
#   bash benchmarks/h2-large-stress.sh [workers] [port] [body_kb] [duration_s] [connections] [threads] [mem_every]
#
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKERS="${1:-4}"
PORT="${2:-19310}"
BODY_KB="${3:-256}"
DUR="${4:-60}"
CONNS="${5:-200}"
THREADS="${6:-4}"
MEM_EVERY="${7:-10000}"

command -v h2load >/dev/null 2>&1 || { echo "需要 h2load：brew install nghttp2"; exit 1; }
PHP_BIN="${PHP_BIN:-$(command -v php)}"

export KODE_BODY_KB="$BODY_KB"
export KODE_MEM_EVERY="$MEM_EVERY"

LOG=/tmp/kode-large.log
: > "$LOG"

cleanup() {
  [ -n "${SERVER_PID:-}" ] && { kill "$SERVER_PID" 2>/dev/null; pkill -P "$SERVER_PID" 2>/dev/null; }
}
trap cleanup EXIT

echo "启动大响应 native 服务（workers=${WORKERS} port=${PORT} body=${BODY_KB}KB）…"
"$PHP_BIN" "${ROOT}/benchmarks/portable-server-large.php" native "$PORT" "$WORKERS" >"$LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 80); do nc -z 127.0.0.1 "$PORT" 2>/dev/null && break; sleep 0.1; done

echo
echo "########## NATIVE HTTP/2（h2c）大响应长时压测：body=${BODY_KB}KB dur=${DUR}s conns=${CONNS} ##########"
h2load -c "$CONNS" -t "$THREADS" --duration "$DUR" --warm-up-time 5 "http://127.0.0.1:${PORT}/" \
  | tee /tmp/kode-large-h2.log

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
  echo "（增长≈0 或远小于单次大响应体，说明大响应缓冲无泄漏；持续增长则提示泄漏）"
else
  echo "未捕获到 MEM 日志行，可能压测时长太短或 worker 未打印。可减小 KODE_MEM_EVERY。"
fi

echo
echo "########## 服务进程启动日志 ##########"
grep -E "large-body worker started|Exception|Error|Fatal" "$LOG" | head -20 || true
