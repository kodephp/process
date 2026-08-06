#!/bin/bash
# 压测矩阵：worker 数 × 连接模式 × 实现
DUR=${DUR:-8}; THR=${THR:-6}; CONN=${CONN:-200}
PORT=9600

stop_all() {
  pkill -9 -f "WorkerMan" 2>/dev/null
  pkill -9 -f "sw-http.php" 2>/dev/null
  pkill -9 -f "spike-bev.php" 2>/dev/null
  pkill -9 -f "spike-evhttp.php" 2>/dev/null
  sleep 1
}

start() { # $1=impl $2=workers $3=port
  case "$1" in
    wm)     BENCH_W=$2 BENCH_PORT=$3 php wm-http.php start >/dev/null 2>&1 & ;;
    bev)    BENCH_W=$2 BENCH_PORT=$3 php spike-bev.php     >/dev/null 2>&1 & ;;
    evhttp) BENCH_W=$2 BENCH_PORT=$3 php spike-evhttp.php  >/dev/null 2>&1 & ;;
    sw)     BENCH_W=$2 BENCH_PORT=$3 php sw-http.php       >/dev/null 2>&1 & ;;
  esac
  for i in $(seq 1 40); do
    curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$3/" && return 0
    sleep 0.25
  done
  return 1
}

bench() { # $1=impl $2=workers $3=mode(ka|close)
  PORT=$((PORT+1))
  stop_all
  if ! start "$1" "$2" "$PORT"; then printf "  %-8s w=%-2s %-6s START-FAIL\n" "$1" "$2" "$3"; return; fi
  local hdr=(); [ "$3" = "close" ] && hdr=(-H "Connection: close")
  wrk -t2 -c20 -d2s "${hdr[@]}" "http://127.0.0.1:$PORT/" >/dev/null 2>&1
  local out; out=$(wrk -t$THR -c$CONN -d${DUR}s --latency "${hdr[@]}" "http://127.0.0.1:$PORT/" 2>&1)
  local qps p99 errs rss
  qps=$(echo "$out"  | awk '/Requests\/sec/{print $2}')
  p99=$(echo "$out"  | awk '/^ *99%/{print $2}')
  errs=$(echo "$out" | awk '/Socket errors/{print $4+$6+$8+$10}')
  rss=$(ps -o rss= -p $(pgrep -f "$1-http.php|spike-$1.php|worker process" 2>/dev/null | head -1) 2>/dev/null | tr -d ' ')
  printf "  %-8s w=%-2s %-6s QPS=%-11s P99=%-9s err=%-4s rss=%sKB\n" \
    "$1" "$2" "$3" "${qps:-FAIL}" "${p99:-n/a}" "${errs:-0}" "${rss:-n/a}"
}

echo "=== 压测矩阵 (dur=${DUR}s threads=$THR conns=$CONN) ==="
for w in 4 8; do
  echo "--- keep-alive, workers=$w ---"
  for impl in wm bev evhttp sw; do bench $impl $w ka; done
done
stop_all
