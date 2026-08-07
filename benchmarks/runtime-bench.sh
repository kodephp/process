#!/usr/bin/env bash
#
# 三运行时 HTTP 吞吐对比压测。
#
# 同一份业务代码（benchmarks/portable-server.php）分别跑在 native / swoole / workerman
# 上，用 wrk 施加完全相同的压力，输出 QPS 与延迟分布。
#
# 用法：
#   bash benchmarks/runtime-bench.sh [workers] [duration] [connections] [threads]
#   bash benchmarks/runtime-bench.sh 4 15 200 4
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKERS="${1:-4}"
DURATION="${2:-15}"
CONNS="${3:-200}"
THREADS="${4:-4}"

command -v wrk >/dev/null 2>&1 || { echo "需要 wrk：brew install wrk"; exit 1; }

RUNTIMES=("native" "swoole" "workerman")
PORTS=(19101 19102 19103)
OUT="${ROOT}/benchmarks/bench-result.txt"

{
  echo "# kode/process 运行时压测"
  echo "日期      : $(date '+%Y-%m-%d %H:%M:%S')"
  echo "PHP       : $(php -r 'echo PHP_VERSION;')"
  echo "CPU 核心  : $(getconf _NPROCESSORS_ONLN 2>/dev/null || sysctl -n hw.ncpu)"
  echo "参数      : workers=${WORKERS} duration=${DURATION}s connections=${CONNS} threads=${THREADS}"
  echo "载荷      : HTTP GET / → 12 字节纯文本"
  echo
} | tee "$OUT"

for i in "${!RUNTIMES[@]}"; do
  RT="${RUNTIMES[$i]}"
  PORT="${PORTS[$i]}"

  # 跳过环境不具备的运行时
  if ! php -r "require '${ROOT}/vendor/autoload.php';
       exit(Kode\\Process\\Runtime::isSupported('${RT}') ? 0 : 1);" 2>/dev/null; then
    echo "== ${RT}: 环境不可用，跳过 ==" | tee -a "$OUT"
    continue
  fi

  php "${ROOT}/benchmarks/portable-server.php" "${RT}" "${PORT}" "${WORKERS}" \
      >"/tmp/kode-bench-${RT}.log" 2>&1 &
  SERVER_PID=$!

  # 等待端口就绪（最多 8s）
  for _ in $(seq 1 80); do
    if nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then break; fi
    sleep 0.1
  done

  if ! nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
    echo "== ${RT}: 启动失败，跳过 ==" | tee -a "$OUT"
    cat "/tmp/kode-bench-${RT}.log" | head -5 | tee -a "$OUT"
    kill "${SERVER_PID}" 2>/dev/null
    continue
  fi

  # 预热，排除 opcache / 首次分配的干扰
  wrk -t2 -c50 -d5s "http://127.0.0.1:${PORT}/" >/dev/null 2>&1

  echo "== ${RT} ==" | tee -a "$OUT"
  wrk -t"${THREADS}" -c"${CONNS}" -d"${DURATION}s" --latency \
      "http://127.0.0.1:${PORT}/" 2>&1 | tee -a "$OUT"
  echo | tee -a "$OUT"

  # 关闭：master 收到 SIGTERM 后优雅退出 worker
  kill -TERM "${SERVER_PID}" 2>/dev/null
  sleep 1
  pkill -f "portable-server.php ${RT} ${PORT}" 2>/dev/null
  sleep 1
done

echo "结果已写入 ${OUT}"
