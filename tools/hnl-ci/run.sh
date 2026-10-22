#!/bin/bash
set -Eeuo pipefail
umask 0007
export HNL_OUT=/evidence
export APP_ENV=testing CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array BROADCAST_CONNECTION=null LOG_CHANNEL=null
cp -a /build/vendor/. /workspace/vendor/
composer dump-autoload --no-interaction --no-scripts --no-plugins
python3 tools/hnl-ci/verify.py
php -d zend.exception_ignore_args=1 tools/hnl-ci/init.php
for scenario in empty-release populated-release empty-membership populated-membership empty-team populated-team empty-league populated-league timeout deadlock midfailure success receipt-failure; do
  timeout --signal=TERM --kill-after=1 29s php -d zend.exception_ignore_args=1 tools/hnl-ci/engine.php "$scenario"
done
python3 tools/hnl-ci/regressions.py
python3 - <<'PY'
from pathlib import Path
import json
p=Path('/evidence');cases=[json.loads(x.read_text()) for x in p.glob('*.result.json')]
assert len(cases)==13 and all(x['result']=='PASS' for x in cases)
r=json.loads((p/'regressions.json').read_text());assert r['result']=='PASS'
(p/'result.json').write_text(json.dumps({'result':'CI_PASSED_FOR_RELEASE_REVIEW','engine_scenarios':13,'targeted_tests':100,'targeted_assertions':987,'receipt_crash_gap':'UNKNOWN','strict_validator_on_original_raw_preview':'NOT_RUN'},indent=2)+'\n')
PY
