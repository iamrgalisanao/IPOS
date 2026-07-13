#!/usr/bin/env bash
set -euo pipefail

export APP_ENV=testing
export APP_DEBUG=false
export BCRYPT_ROUNDS=4
export BROADCAST_CONNECTION=null
export CACHE_STORE=array
export DB_CONNECTION=sqlite
export DB_DATABASE=database/ci.sqlite
export ENFORCE_TIMECARDS=false
export MAIL_MAILER=array
export NIGHTWATCH_ENABLED=false
export PULSE_ENABLED=false
export QUEUE_CONNECTION=sync
export SESSION_DRIVER=array
export TELESCOPE_ENABLED=false

cp .env.example .env
php artisan key:generate --no-interaction
touch database/ci.sqlite
php artisan migrate:fresh --force --no-interaction
php artisan route:list
php -d memory_limit=-1 ./vendor/bin/pest
npm run build
