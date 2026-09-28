#!/usr/bin/env bash
set -euo pipefail
export LC_ALL=C

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
cd "$ROOT"

fail() { printf 'VERIFY FAILED: %s\n' "$*" >&2; exit 1; }

expected=$(mktemp)
actual=$(mktemp)
first=$(mktemp)
second=$(mktemp)
trap 'rm -f "$expected" "$actual" "$first" "$second"' EXIT

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
./phase1_harness.php --mode=rehearse >"$first"
./phase1_harness.php --mode=rehearse >"$second"
cmp -s "$first" "$second" || fail 'rehearsal is not deterministic'
[[ $(sha256sum "$first" | awk '{print $1}') == 8587fd8c6fa2d3078472de561b543b52cc8a7ef89d9e13f197a60d05c86d1cb5 ]] \
    || fail 'rehearsal seal mismatch'
php -r '$x=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if(count($x["faults"])!==25){exit(1);} foreach($x["faults"] as $r){if($r["passed"]!==true){exit(1);}}' "$first" \
    || fail 'fault assertion failed'
printf '%s\n' VERIFIED
