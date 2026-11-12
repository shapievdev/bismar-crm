#!/usr/bin/env bash
#
# Поднимает всё, что нужно разработке, одной командой: ./start.sh
#
#   api    — Laravel на 8100
#   reverb — сокет-сервер мессенджера на REVERB_PORT из back/.env
#   queue  — обработчик очереди: на ней живут веб-пуши и векторизация уроков
#            (сообщения чата идут мимо очереди, они ShouldBroadcastNow)
#   schedule — ежедневные уведомления и напоминания
#   front  — Nuxt на 3100
#
# Ctrl+C останавливает все процессы. Если падает один, скрипт гасит остальные:
# полуживой набор процессов отлаживать хуже, чем пустой терминал.
#
# Порты переопределяются переменными окружения: API_PORT=8200 ./start.sh
#
set -uo pipefail

cd "$(dirname "$0")"

API_PORT=${API_PORT:-8100}
FRONT_PORT=${FRONT_PORT:-3100}

# Ключ и порт Reverb берём из back/.env, а не из nuxt.config.ts: там ключ
# намеренно пустой (мессенджер без сокетов — рабочий случай), и подставить его
# в разработке дешевле здесь, чем держать второй .env на фронте.
env_value() {
    local value
    value=$(grep -E "^$1=" back/.env 2>/dev/null | tail -1 | cut -d= -f2-)
    printf '%s' "${value//\"/}"
}

say() { printf '\033[36m==>\033[0m %s\n' "$1"; }
die() { printf '\033[31m==>\033[0m %s\n' "$1" >&2; exit 1; }

# --- Чего не хватает -------------------------------------------------------

command -v php >/dev/null || die "Нет php. Нужен 8.3+."
command -v node >/dev/null || die "Нет node. Нужен 22.19+ (ветка 22), 24.11+ (ветка 24) или 26+."

if [ ! -f back/.env ]; then
    say "back/.env не было — беру .env.example и генерирую ключ"
    cp back/.env.example back/.env
    (cd back && php artisan key:generate)
fi

if [ ! -d back/vendor ]; then
    say "Ставлю зависимости API"
    (cd back && composer install) || die "composer install не прошёл"
fi

if [ ! -d front/node_modules ]; then
    say "Ставлю зависимости приложения"
    (cd front && npm install) || die "npm install не прошёл"
fi

REVERB_PORT=$(env_value REVERB_PORT)
REVERB_PORT=${REVERB_PORT:-8080}
REVERB_KEY=$(env_value REVERB_APP_KEY)

if command -v pg_isready >/dev/null && ! pg_isready -q 2>/dev/null; then
    say "PostgreSQL не отвечает — API поднимется, но каждый запрос упадёт"
fi

# Занятый порт не отбираем: на нём почти всегда сидит прошлый запуск этого же
# скрипта, и убить его молча — потерять чьи-то логи.
busy() { lsof -nP -iTCP:"$1" -sTCP:LISTEN -t 2>/dev/null | head -1; }

for port in "$API_PORT:api" "$REVERB_PORT:reverb" "$FRONT_PORT:front"; do
    pid=$(busy "${port%%:*}")
    [ -n "$pid" ] && die "Порт ${port%%:*} (${port##*:}) занят процессом $pid. Освободите: kill $pid"
done

# --- Запуск ----------------------------------------------------------------

names=()
pids=()

run() {
    local name=$1 dir=$2
    shift 2
    ( cd "$dir" && exec "$@" ) 2>&1 |
        awk -v tag="$name" '{ printf "%-6s | %s\n", tag, $0; fflush() }' &
    names+=("$name")
    pids+=($!)
}

# kill 0 бьёт по всей группе процессов — и по детям, которых awk в конвейере
# своим pid не прикрывает.
stop() {
    trap '' INT TERM EXIT
    printf '\n'
    say "Останавливаю"
    kill 0 2>/dev/null
}
trap stop INT TERM EXIT

run api    back  php artisan serve --port="$API_PORT"
run reverb back  php artisan reverb:start
run queue  back  php artisan queue:listen --tries=1 --timeout=0
run schedule back php artisan schedule:work
run front  front env \
    NUXT_PUBLIC_API_BASE="http://localhost:$API_PORT" \
    NUXT_PUBLIC_REVERB_KEY="$REVERB_KEY" \
    NUXT_PUBLIC_REVERB_PORT="$REVERB_PORT" \
    npm run dev -- --port "$FRONT_PORT"

printf '\n'
say "Приложение — http://localhost:$FRONT_PORT, API — http://localhost:$API_PORT"
say "Вход: +79000000001 / password. Ctrl+C останавливает всё."
printf '\n'

# `wait -n` здесь нельзя: в bash 3.2, который стоит в macOS по умолчанию, его
# нет. Поэтому ждём опросом — awk в конвейере умирает вместе со своим процессом.
while :; do
    for i in "${!pids[@]}"; do
        if ! kill -0 "${pids[$i]}" 2>/dev/null; then
            say "${names[$i]} остановился"
            exit 1
        fi
    done
    sleep 1
done
