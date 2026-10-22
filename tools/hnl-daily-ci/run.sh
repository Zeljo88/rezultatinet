#!/bin/bash
set -Eeuo pipefail
umask 0007
cp -a /build/vendor/. /workspace/vendor/
composer dump-autoload --no-interaction --no-scripts --no-plugins
python3 tools/hnl-daily-ci/suite.py
