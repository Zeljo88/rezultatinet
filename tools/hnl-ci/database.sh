#!/bin/bash
set -Eeuo pipefail
if getent passwd 1000 >/dev/null; then
 usermod -l ci "$(getent passwd 1000 | cut -d: -f1)"
else
 useradd -u 1000 -M ci
fi
install -d -o mysql -g mysql -m 0755 /tmp/hnl-ci
mariadb-install-db --skip-test-db --user=mysql --datadir=/var/lib/mysql --auth-root-authentication-method=socket > /tmp/install.log 2>&1
exec mariadbd --user=mysql --datadir=/var/lib/mysql --socket=/tmp/hnl-ci/mariadb.sock --pid-file=/tmp/hnl-ci/mariadb.pid --skip-networking --innodb-flush-log-at-trx-commit=1 --innodb-lock-wait-timeout=5 --lock-wait-timeout=5
