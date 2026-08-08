#!/usr/bin/env bash
#
# 三运行时同等条件对比压测（交替多轮）。
#
# 与 runtime-bench.sh 的区别在于两点，都是为了让对比结论站得住：
#
#  1. 交替轮转，而不是「跑完一个再跑下一个」。
#     顺序压测里，先跑的和后跑的面对的机器状态并不相同（缓存温度、其它进程的
#     CPU 占用都在漂移），差距会被系统性地记到某一方头上。交替执行并取多轮
#     中位数，可以把这种漂移摊平。
#
#  2. 三方使用完全一致的 PHP 配置（含 OPcache/JIT 开关）。
#     JIT 只对 PHP 字节码有效：native 与 workerman 的热路径是 PHP，能吃到收益；
#     swoole 的热路径在 C 扩展里，收益有限。这不是偏袒谁，而是各自架构的客观
#     结果——真实部署时用户面对的正是同一个 php.ini，所以这才是「同等条件」。
#     用 JIT=0 可以关掉 JIT 复现无 JIT 场景。
#
# 用法：
#   bash benchmarks/runtime-compare.sh [workers] [duration] [connections] [threads] [rounds]
#   JIT=0 bash benchmarks/runtime-compare.sh 4 10 200 4 3
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKERS="${1:-4}"
DURATION="${2:-10}"
CONNS="${3:-200}"
THREADS="${4:-4}"
ROUNDS="${5:-3}"
JIT="${JIT:-1}"

command -v wrk >/dev/null 2>&1 || { echo "需要 wrk：brew install wrk"; exit 1; }

RUNTIMES=("native" "swoole" "workerman")
PORTS=(19101 19102 19103)
OUT="${ROOT}/benchmarks/compare-result.txt"

if [ "$JIT" = "1" ]; then
  PHP_OPTS=(-d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M)
  JIT_LABEL="OPcache + JIT(tracing, 64M)"
else
  # 显式关闭而不是留空数组：macOS 自带 bash 3.2 在 set -u 下展开空数组会报 unbound
  PHP_OPTS=(-d opcache.enable_cli=0 -d opcache.jit=off)
  JIT_LABEL="无 OPcache / 无 JIT"
fi

cleanup() {
  for rt in "${RUNTIMES[@]}"; do
    pkill -f "portable-server.php ${rt}" >/dev/null 2>&1
  done
  sleep 0.5
}
trap cleanup EXIT
cleanup

# 服务端进程树累计 CPU 时间（秒）。master + 全部 worker 一起算。
#
# 机器核心有限时，wrk 与被测服务抢同一批 CPU，多 worker 下三方 QPS 会一起撞上
# 压测端的天花板，纯吞吐就分不出高下了。此时「每消耗一秒 CPU 能处理多少请求」
# 才是仍然可比的服务端指标——同样的 QPS 下谁更省 CPU，谁在真实负载下就有更多余量。
cpu_seconds() {
  local rt="$1"
  # native / workerman 会改写进程标题（`bench-native: worker #0` 之类），
  # swoole 保留原始命令行，两种形态都要匹配到，否则会漏算 worker 的 CPU。
  ps -Ao time=,command= 2>/dev/null \
    | grep -E "portable-server\.php ${rt}|bench-${rt}" \
    | grep -v grep \
    | awk '{
        split($1, t, ":");
        if (length(t) == 3) { s = t[1]*3600 + t[2]*60 + t[3]; }
        else                { s = t[1]*60 + t[2]; }
        total += s;
      } END { printf "%.2f", total + 0 }'
}

# 单次压测：启动 → 预热 → 计时 → 关停，返回 "QPS CPU秒"
bench_once() {
  local rt="$1" port="$2"

  php "${PHP_OPTS[@]}" "${ROOT}/benchmarks/portable-server.php" "$rt" "$port" "$WORKERS" \
      >"/tmp/kode-cmp-${rt}.log" 2>&1 &
  local pid=$!

  local ready=0
  for _ in $(seq 1 80); do
    if nc -z 127.0.0.1 "$port" 2>/dev/null; then ready=1; break; fi
    sleep 0.1
  done
  if [ "$ready" = "0" ]; then
    kill "$pid" 2>/dev/null
    echo "0"
    return
  fi

  # 预热：让 JIT 完成 tracing、连接池与分配器进入稳态
  wrk -t2 -c50 -d4s "http://127.0.0.1:${port}/" >/dev/null 2>&1

  # 预热之后再取基线，这样计时窗口内的 CPU 只包含稳态处理开销，不含启动与 JIT 编译
  local cpu_before cpu_after
  cpu_before=$(cpu_seconds "$rt")

  local out
  out=$(wrk -t"$THREADS" -c"$CONNS" -d"${DURATION}s" --latency "http://127.0.0.1:${port}/" 2>&1)
  echo "$out" > "/tmp/kode-cmp-${rt}.wrk"

  cpu_after=$(cpu_seconds "$rt")

  kill -TERM "$pid" 2>/dev/null
  sleep 0.8
  pkill -f "portable-server.php ${rt} ${port}" >/dev/null 2>&1
  sleep 0.7

  local qps
  qps=$(echo "$out" | awk '/Requests\/sec/{print $2}')

  awk -v q="${qps:-0}" -v a="$cpu_before" -v b="$cpu_after" -v d="$DURATION" 'BEGIN{
    cpu = b - a;
    printf "%s %.2f %.0f", q, cpu, (cpu > 0 ? q * d / cpu : 0);
  }'
}

median() {
  printf '%s\n' "$@" | sort -n | awk '{a[NR]=$1} END{ if(NR==0){print 0} else if(NR%2){printf "%.2f", a[(NR+1)/2]} else {printf "%.2f", (a[NR/2]+a[NR/2+1])/2} }'
}

{
  echo "# kode/process 三运行时同等条件对比"
  echo "日期      : $(date '+%Y-%m-%d %H:%M:%S')"
  echo "PHP       : $(php -r 'echo PHP_VERSION;')"
  echo "CPU 核心  : $(getconf _NPROCESSORS_ONLN 2>/dev/null || sysctl -n hw.ncpu)"
  echo "PHP 配置  : ${JIT_LABEL}"
  echo "参数      : workers=${WORKERS} duration=${DURATION}s connections=${CONNS} threads=${THREADS} rounds=${ROUNDS}"
  echo "载荷      : HTTP GET / → 12 字节纯文本"
  echo "方法      : 三方交替轮转，取各自多轮 QPS 中位数"
  echo
} | tee "$OUT"

declare -a Q_native Q_swoole Q_workerman
declare -a E_native E_swoole E_workerman

for r in $(seq 1 "$ROUNDS"); do
  echo "--- 第 ${r}/${ROUNDS} 轮 ---" | tee -a "$OUT"
  for i in "${!RUNTIMES[@]}"; do
    rt="${RUNTIMES[$i]}"
    port="${PORTS[$i]}"

    if ! php -r "require '${ROOT}/vendor/autoload.php';
         exit(Kode\\Process\\Runtime::isSupported('${rt}') ? 0 : 1);" 2>/dev/null; then
      printf "  %-10s 环境不可用，跳过\n" "$rt" | tee -a "$OUT"
      continue
    fi

    read -r q cpu eff <<<"$(bench_once "$rt" "$port")"
    printf "  %-10s %12s req/s   CPU %6ss   %10s req/CPU秒\n" "$rt" "$q" "$cpu" "$eff" | tee -a "$OUT"

    case "$rt" in
      native)    Q_native+=("$q");    E_native+=("$eff") ;;
      swoole)    Q_swoole+=("$q");    E_swoole+=("$eff") ;;
      workerman) Q_workerman+=("$q"); E_workerman+=("$eff") ;;
    esac
  done
done

echo | tee -a "$OUT"
echo "=== 中位数汇总 ===" | tee -a "$OUT"

M_NATIVE=$( [ ${#Q_native[@]} -gt 0 ]    && median "${Q_native[@]}"    || echo 0 )
M_SWOOLE=$( [ ${#Q_swoole[@]} -gt 0 ]    && median "${Q_swoole[@]}"    || echo 0 )
M_WM=$(     [ ${#Q_workerman[@]} -gt 0 ] && median "${Q_workerman[@]}" || echo 0 )

printf "  %-10s %12s req/s\n" "native"    "$M_NATIVE"  | tee -a "$OUT"
printf "  %-10s %12s req/s\n" "swoole"    "$M_SWOOLE"  | tee -a "$OUT"
printf "  %-10s %12s req/s\n" "workerman" "$M_WM"      | tee -a "$OUT"

echo | tee -a "$OUT"
awk -v n="$M_NATIVE" -v s="$M_SWOOLE" -v w="$M_WM" 'BEGIN{
  if (s > 0) printf "  native vs swoole    : %+.1f%%\n", (n/s-1)*100;
  if (w > 0) printf "  native vs workerman : %+.1f%%\n", (n/w-1)*100;
}' | tee -a "$OUT"

echo | tee -a "$OUT"
echo "=== CPU 效率中位数（req / CPU 秒） ===" | tee -a "$OUT"

E_NATIVE=$( [ ${#E_native[@]} -gt 0 ]    && median "${E_native[@]}"    || echo 0 )
E_SWOOLE=$( [ ${#E_swoole[@]} -gt 0 ]    && median "${E_swoole[@]}"    || echo 0 )
E_WM=$(     [ ${#E_workerman[@]} -gt 0 ] && median "${E_workerman[@]}" || echo 0 )

printf "  %-10s %12s req/CPU秒\n" "native"    "$E_NATIVE" | tee -a "$OUT"
printf "  %-10s %12s req/CPU秒\n" "swoole"    "$E_SWOOLE" | tee -a "$OUT"
printf "  %-10s %12s req/CPU秒\n" "workerman" "$E_WM"     | tee -a "$OUT"

echo | tee -a "$OUT"
awk -v n="$E_NATIVE" -v s="$E_SWOOLE" -v w="$E_WM" 'BEGIN{
  if (s > 0) printf "  native vs swoole    : %+.1f%%\n", (n/s-1)*100;
  if (w > 0) printf "  native vs workerman : %+.1f%%\n", (n/w-1)*100;
}' | tee -a "$OUT"

echo | tee -a "$OUT"
echo "结果已写入 ${OUT}"
