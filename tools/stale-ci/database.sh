#!/bin/bash
set -Eeuo pipefail
if getent passwd 1000 >/dev/null; then
  usermod -l ci "$(getent passwd 1000 | cut -d: -f1)"
else
  useradd -u 1000 -M ci
fi
install -d -o mysql -g mysql -m 0755 /tmp/stale-reconciliation-ci
mariadb-install-db --user=mysql --datadir=/var/lib/mysql --auth-root-authentication-method=socket > /tmp/install.log 2>&1
exec mariadbd --user=mysql --datadir=/var/lib/mysql --socket=/tmp/stale-reconciliation-ci/mariadb.sock --pid-file=/tmp/stale-reconciliation-ci/mariadb.pid --skip-networking --innodb-flush-log-at-trx-commit=1
