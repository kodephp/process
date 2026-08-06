#!/bin/bash
# 短连接场景：每请求都 accept+close，放大连接建立开销
DUR=${DUR:-6}; W=4; PORT=9900
stop_all() { pkill -9 -f "WorkerMan" 2>/dev/null; pkill -9 -f "sw-http.php" 2>/dev/null; pkill -9 -f "spike-bev.php" 2>/dev/null; pkill -9 -f "spike-evhttp.php" 2>/dev/null; sleep 1; }
start() {
  case "$1" in
    wm)     BENCH_W=$2 BENCH_PORT=$3 php wm-http.php start >/dev/null 2>&1 & ;;
    bev)    BENCH_W=$2 BENCH_PORT=$3 php spike-bev.php     >/dev/null 2>&1 & ;;
    evhttp) BENCH_W=$2 BENCH_PORT=$3 php spike-evhttp.php  >/dev/null 2>&1 & ;;
    sw)     BENCH_W=$2 BENCH_PORT=$3 php sw-http.php       >/dev/null 2>&1 & ;;
  esac
  for i in $(seq 1 40); do curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$3/" && return 0; sleep 0.25; done
  return 1
}
echo "=== 短连接 (Connection: close) workers=$W dur=${DUR}s ==="
for impl in wm bev evhttp sw; do
  PORT=$((PORT+1)); stop_all
  start "$impl" "$W" "$PORT" || { echo "$impl START-FAIL"; continue; }
  wrk -t2 -c20 -d2s -H "Connection: close" "http://127.0.0.1:$PORT/" >/dev/null 2>&1
  OUT=$(wrk -t4 -c100 -d${DUR}s --latency -H "Connection: close" "http://127.0.0.1:$PORT/" 2>&1)
  QPS=$(echo "$OUT" | awk '/Requests\/sec/{print $2}')
  P99=$(echo "$OUT" | awk '/^ *99%/{print $2}')
  ERR=$(echo "$OUT" | grep "Socket errors" || echo "  no socket errors")
  printf "  %-8s QPS=%-11s P99=%-9s %s\n" "$impl" "${QPS:-FAIL}" "${P99:-n/a}" "$ERR"
done
stop_all
