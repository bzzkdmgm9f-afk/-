#!/bin/sh
set -eu
cd "$(dirname "$0")"
php_bin="${PHP_BINARY:-php}"
port="${1:-8088}"
interval="${AUCTION_MAINTENANCE_INTERVAL:-5}"
case "$port" in
  ''|*[!0-9]*) echo 'Choose a numeric local port.' >&2; exit 1 ;;
esac
case "$interval" in
  ''|*[!0-9]*) echo 'Choose a numeric maintenance interval in seconds.' >&2; exit 1 ;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ] || [ "$interval" -lt 1 ] || [ "$interval" -gt 3600 ]; then
  echo 'Use a port from 1 to 65535 and a maintenance interval from 1 to 3600 seconds.' >&2
  exit 1
fi

umask 077
runtime="${AUCTION_RUNTIME_DIR:-$PWD/runtime}"
mkdir -p "$runtime"
server_log="$(mktemp "$runtime/server.XXXXXX")"
server_pid=''
worker_pid=''
log_pid=''
cleanup() {
  trap - EXIT INT TERM HUP
  for child in "$worker_pid" "$server_pid" "$log_pid"; do
    if [ -n "$child" ]; then
      kill "$child" 2>/dev/null || true
    fi
  done
  for child in "$worker_pid" "$server_pid" "$log_pid"; do
    if [ -n "$child" ]; then
      wait "$child" 2>/dev/null || true
    fi
  done
  rm -f "$server_log"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

unset PHP_CLI_SERVER_WORKERS
"$php_bin" -d upload_max_filesize=5M -d post_max_size=32M -S "127.0.0.1:$port" router.php >"$server_log" 2>&1 &
server_pid=$!
attempt=0
while ! grep -q 'Development Server .* started' "$server_log"; do
  if ! kill -0 "$server_pid" 2>/dev/null; then
    cat "$server_log" >&2
    exit 1
  fi
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 100 ]; then
    echo 'The local server did not become ready.' >&2
    cat "$server_log" >&2
    exit 1
  fi
  sleep 0.1
done

MAIL_TRANSPORT=file "$php_bin" tools/maintenance.php "--interval=$interval" &
worker_pid=$!
tail -n +1 -f "$server_log" &
log_pid=$!
echo "Local auction maintenance runs every $interval seconds. Email previews stay in the private runtime outbox."
while kill -0 "$server_pid" 2>/dev/null && kill -0 "$worker_pid" 2>/dev/null; do
  sleep 1
done
if ! kill -0 "$worker_pid" 2>/dev/null; then
  echo 'The local maintenance process stopped; shutting down the server.' >&2
  exit 1
fi
wait "$server_pid"
