#!/usr/bin/env bash
set -Eeuo pipefail

backend_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
frontend_dir="${JOIN_FRONTEND_DIR:-$backend_dir/../frontend}"
service_pids=()

cleanup() {
    for service_pid in "${service_pids[@]}"; do
        kill -- "-$service_pid" 2>/dev/null || true
    done
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for dependency in php composer node npm python3 docker curl setsid; do
    command -v "$dependency" >/dev/null || { echo "Missing required command: $dependency" >&2; exit 1; }
done
[[ -f "$frontend_dir/package.json" ]] || { echo 'Frontend checkout not found. Set JOIN_FRONTEND_DIR to its directory.' >&2; exit 1; }
cd -- "$backend_dir"
python3 scripts/prepare-local-env.py
if ! php -r 'exit(extension_loaded("bcmath") ? 0 : 1);'; then
    echo "PHP BCMath is required by LiveKit. Install/enable the BCMath extension for your PHP version (for PHP 8.4 on Ubuntu: sudo apt install php8.4-bcmath), then rerun this script." >&2
    exit 1
fi
if ! docker info >/dev/null 2>&1; then
    echo 'Docker is unavailable. Run this script in a regular terminal with Docker access; the restricted agent session cannot start these services.' >&2
    exit 1
fi
docker compose version >/dev/null

if ! php -r 'if (!is_file("vendor/autoload.php")) { exit(1); } require "vendor/autoload.php"; exit(class_exists("Agence104\\LiveKit\\RoomServiceClient") && class_exists("Kreait\\Firebase\\Factory") ? 0 : 1);'; then
    composer install --no-interaction --prefer-dist
fi
if [[ ! -x "$frontend_dir/node_modules/.bin/vite" ]]; then
    if [[ -f "$frontend_dir/package-lock.json" ]]; then
        (cd -- "$frontend_dir" && npm ci)
    else
        (cd -- "$frontend_dir" && npm install)
    fi
fi
php artisan config:clear
php artisan migrate --force --no-interaction
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d

log_dir="$backend_dir/storage/logs"
mkdir -p -- "$log_dir"
if ! curl --silent --fail --max-time 2 http://127.0.0.1:8000/up >/dev/null; then
    setsid php artisan serve --host=0.0.0.0 --port=8000 >"$log_dir/local-api.log" 2>&1 &
    service_pids+=("$!")
fi
if ! curl --silent --fail --max-time 2 http://127.0.0.1:5173 >/dev/null; then
    (cd -- "$frontend_dir" && exec setsid npm run dev -- --host 127.0.0.1 --port 5173 --strictPort) >"$log_dir/local-frontend.log" 2>&1 &
    service_pids+=("$!")
fi
setsid php artisan schedule:work >"$log_dir/local-scheduler.log" 2>&1 &
service_pids+=("$!")
setsid php artisan queue:work --sleep=3 --tries=3 >"$log_dir/local-queue.log" 2>&1 &
service_pids+=("$!")

ready=false
for attempt in {1..20}; do
    if curl --silent --fail --max-time 2 http://127.0.0.1:8000/up >/dev/null && curl --silent --fail --max-time 2 http://127.0.0.1:5173 >/dev/null; then
        ready=true
        break
    fi
    sleep 0.5
done
if [[ "$ready" != true ]]; then
    echo "App startup failed. See $log_dir/local-api.log and local-frontend.log." >&2
    exit 1
fi
printf '%s\n' 'App processes are ready:' '  Frontend: http://localhost:5173' '  API:      http://localhost:8000' '  LiveKit:  ws://localhost:7880' 'Check Docker logs for media-server readiness. No browser media test has been run.' 'Press Ctrl+C to stop the app processes started here. Existing processes are left running.' 'Docker containers remain running; stop them with:' '  docker compose --env-file deploy/.env -f deploy/compose.yaml stop'
wait
