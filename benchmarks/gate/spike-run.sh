#!/bin/bash
# spike 快速对照：Workerman vs bufferevent vs EventHttp
W=${W:-4}; DUR=${DUR:-8}; CONN=${CONN:-100}; THR=${THR:-4}
run() {
  local name="$1" script="$2" port="$3"
  php "$script" "$W" "$port" >/dev/null 2>&1 &
  local pid=$!
  sleep 1.5
  # 预热
  wrk -t2 -c20 -d2s "http://127.0.0.1:${port}/" >/dev/null 2>&1
  local out
  out=$(wrk -t$THR -c$CONN -d${DUR}s --latency "http://127.0.0.1:${port}/" 2>&1)
  local qps p99 err
  qps=$(echo "$out" | awk '/Requests\/sec/{print $2}')
  p99=$(echo "$out" | awk '/99%/{print $2}')
  err=$(echo "$out" | awk '/Socket errors/{print $0}')
  local rss
  rss=$(ps -o rss= -p $(pgrep -P $pid 2>/dev/null | head -1) 2>/dev/null | tr -d ' ')
  printf "%-14s QPS=%-12s P99=%-10s RSS/worker=%sKB %s\n" "$name" "$qps" "$p99" "${rss:-n/a}" "$err"
  kill -9 $pid 2>/dev/null
  pkill -9 -f "$script" 2>/dev/null
  sleep 1
}
echo "workers=$W conns=$CONN dur=${DUR}s threads=$THR"
run "Workerman5"  wm-http.php      9501
run "bufferevent" spike-bev.php    9502
run "EventHttp"   spike-evhttp.php 9503
