#!/bin/bash
set -Eeuo pipefail
cd /workspace
mkdir -p vendor storage/framework/{views,cache,sessions} storage/logs bootstrap/cache /evidence
cp -a /build/vendor/. vendor/
composer dump-autoload --optimize --no-scripts --no-plugins > /evidence/autoload.txt 2>&1
test ! -e .env
test ! -e bootstrap/cache/config.php
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array BROADCAST_CONNECTION=null
export STALE_TEST_ALLOW_FIXTURES=1 STALE_TEST_DATABASE=stale_reconciliation_test_ci STALE_TEST_SOCKET=/tmp/stale-reconciliation-ci/mariadb.sock STALE_CI_RECEIPTS=/evidence/scenarios.jsonl
python3 tools/stale-ci/run-tests.py
python3 tools/stale-ci/redis-isolated.py
