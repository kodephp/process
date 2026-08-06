#!/bin/bash
# 每请求 CPU 成本测量：穿透客户端天花板的真实效率指标
DUR=${DUR:-10}; THR=${THR:-6}; CONN=${CONN:-200}; W=${W:-4}
PORT=9800

pat() { case "$1" in wm) echo "WorkerMan";; bev) echo "spike-bev.php";; evhttp) echo "spike-evhttp.php";; sw) echo "sw-http.php";; esac; }

# 累加匹配进程的 CPU 时间（秒）
cputime() {
  local total=0 t
  for p in $(pgrep -f "$1" 2>/dev/null); do
    t=$(ps -o time= -p $p 2>/dev/null | tr -d ' ')
    [ -z "$t" ] && continue
    total=$(echo "$t" | awk -F: -v tot="$total" '{
      n=NF; s=0; m=1;
      for(i=n;i>=1;i--){ s += $i * m; m *= 60 }
      print tot + s
    }')
  done
  echo "$total"
}

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

printf "%-8s %-12s %-10s %-14s %-10s\n" IMPL QPS CPU秒 微秒/请求 相对Workerman
BASE=""
for impl in wm bev evhttp sw; do
  PORT=$((PORT+1)); stop_all
  start "$impl" "$W" "$PORT" || { echo "$impl START-FAIL"; continue; }
  P=$(pat $impl)
  wrk -t2 -c20 -d3s "http://127.0.0.1:$PORT/" >/dev/null 2>&1   # 预热
  C0=$(cputime "$P")
  OUT=$(wrk -t$THR -c$CONN -d${DUR}s "http://127.0.0.1:$PORT/" 2>&1)
  C1=$(cputime "$P")
  QPS=$(echo "$OUT" | awk '/Requests\/sec/{print $2}')
  REQ=$(echo "$OUT" | awk '/requests in/{print $1}')
  US=$(echo "$C0 $C1 $REQ" | awk '{ if($3>0) printf "%.3f", ($2-$1)*1000000/$3; else print "n/a" }')
  [ -z "$BASE" ] && BASE=$US
  REL=$(echo "$US $BASE" | awk '{ if($2>0) printf "%.2fx", $1/$2; else print "-" }')
  printf "%-8s %-12s %-10s %-14s %-10s\n" "$impl" "$QPS" "$(echo "$C0 $C1" | awk '{printf "%.2f", $2-$1}')" "$US" "$REL"
done
stop_all
