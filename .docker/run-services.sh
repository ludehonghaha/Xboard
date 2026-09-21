#!/bin/sh
set -u

PIDS=""
STOPPING=0

start_as() {
  user="$1"
  shift
  su-exec "$user" "$@" &
  pid=$!
  PIDS="$PIDS $pid"
  echo "[services] started pid=$pid: $*"
}

shutdown_all() {
  [ "$STOPPING" -eq 1 ] && return
  STOPPING=1
  echo "[services] stopping:$PIDS"
  for pid in $PIDS; do
    kill -TERM "$pid" 2>/dev/null || true
  done

  i=0
  while [ "$i" -lt 8 ]; do
    alive=0
    for pid in $PIDS; do
      kill -0 "$pid" 2>/dev/null && alive=1
    done
    [ "$alive" -eq 0 ] && break
    sleep 1
    i=$((i + 1))
  done

  for pid in $PIDS; do
    kill -KILL "$pid" 2>/dev/null || true
  done
}

trap 'shutdown_all; exit 0' TERM INT QUIT HUP

if [ "${ENABLE_REDIS:-true}" = "true" ]; then
  start_as redis redis-server \
    --dir /data \
    --dbfilename dump.rdb \
    --save 900 1 \
    --save 300 10 \
    --save 60 10000 \
    --port 0 \
    --unixsocket /data/redis.sock \
    --unixsocketperm 777
fi

if [ "${ENABLE_WEB:-true}" = "true" ]; then
  start_as www php /www/artisan octane:start \
    --host="${OCTANE_HOST}" \
    --port="${OCTANE_PORT}" \
    --workers="${OCTANE_WORKERS}" \
    --task-workers="${OCTANE_TASK_WORKERS}" \
    --max-requests="${OCTANE_MAX_REQUESTS}"
fi

if [ "${ENABLE_HORIZON:-true}" = "true" ]; then
  start_as www php /www/artisan horizon
fi

if [ "${ENABLE_WS_SERVER:-true}" = "true" ]; then
  start_as www php /www/artisan ws-server start \
    --host="${WS_HOST}" \
    --port="${WS_PORT}"
fi

if [ "${ENABLE_PROXY:-true}" = "true" ]; then
  nginx -g 'daemon off;' &
  pid=$!
  PIDS="$PIDS $pid"
  echo "[services] started pid=$pid: nginx"
fi

if [ -z "$(echo "$PIDS" | tr -d ' ')" ]; then
  echo "[services] no services enabled" >&2
  exit 1
fi

while :; do
  for pid in $PIDS; do
    if ! kill -0 "$pid" 2>/dev/null; then
      wait "$pid" 2>/dev/null
      rc=$?
      echo "[services] child pid=$pid exited rc=$rc; restarting container" >&2
      shutdown_all
      exit "${rc:-1}"
    fi
  done
  sleep 1
done
