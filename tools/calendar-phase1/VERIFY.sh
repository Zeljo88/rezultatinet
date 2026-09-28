#!/usr/bin/env bash
set -euo pipefail
export LC_ALL=C

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
cd "$ROOT"

fail() { printf 'VERIFY FAILED: %s\n' "$*" >&2; exit 1; }

expected=$(mktemp)
actual=$(mktemp)
trap 'rm -f "$expected" "$actual"' EXIT

cat >"$expected" <<'EOF'
d	.	755
f	FAULT-MATRIX.md	644
f	README.md	644
f	SHA256SUMS	644
f	VERIFY.sh	755
f	health-urls.example.json	644
f	phase1_harness.php	755
EOF
find . -mindepth 0 -printf '%y\t%P\t%m\n' | sed 's/^d\t\t/d\t.\t/' | sort >"$actual"
cmp -s "$expected" "$actual" || fail 'node/type/mode allowlist mismatch'

for file in FAULT-MATRIX.md README.md SHA256SUMS VERIFY.sh health-urls.example.json phase1_harness.php; do
    cmp -s "$file" <(tr -d '\000' <"$file") || fail "NUL byte in $file"
    iconv -f UTF-8 -t UTF-8 "$file" >/dev/null || fail "invalid UTF-8 in $file"
done

sha256sum --check --strict SHA256SUMS >/dev/null || fail 'checksum mismatch'
php -l phase1_harness.php >/dev/null || fail 'PHP lint failed'
grep -Fq "hash_hmac('sha256'" phase1_harness.php || fail 'authenticated records missing'
grep -Fq "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED" phase1_harness.php \
    || fail 'recovery isolation missing'
grep -Fq 'readAuthenticated' phase1_harness.php || fail 'authenticated recovery missing'
grep -Fq 'lockForUpdate' phase1_harness.php || fail 'locking reads missing'
grep -Fq "'authentication-key'" phase1_harness.php || fail 'authentication key contract missing'
if grep -Eq 'simulate_fault|mode=rehearse' phase1_harness.php README.md FAULT-MATRIX.md; then
    fail 'disconnected rehearsal remains'
fi
printf '%s\n' VERIFIED_STATIC
