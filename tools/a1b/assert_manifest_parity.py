#!/usr/bin/env python3
"""Compare an information_schema capture with the A1a manifest for critical schema objects."""
from __future__ import annotations
import argparse, json, sys
from pathlib import Path

CRITICAL = {"players", "player_stats", "posts", "standings", "teams", "fixtures", "fixture_events", "fixture_scores", "predictions"}

def normalized(table: dict) -> dict:
    return {
        "columns": [(c["COLUMN_NAME"], c["COLUMN_TYPE"], c["IS_NULLABLE"], c["COLUMN_DEFAULT"], c.get("EXTRA", "")) for c in table["columns"]],
        "indexes": sorted((i["INDEX_NAME"], i["NON_UNIQUE"], i["SEQ_IN_INDEX"], i["COLUMN_NAME"], i.get("SUB_PART")) for i in table["indexes"]),
        "foreign_keys": sorted((f["CONSTRAINT_NAME"], f["ORDINAL_POSITION"], f["COLUMN_NAME"], f["REFERENCED_TABLE_NAME"], f["REFERENCED_COLUMN_NAME"], f["UPDATE_RULE"], f["DELETE_RULE"]) for f in table.get("foreign_keys", [])),
        "checks": sorted((c["CONSTRAINT_NAME"], c["CHECK_CLAUSE"].replace(" ", "")) for c in table.get("check_constraints", [])),
    }

def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("expected", type=Path)
    p.add_argument("actual", type=Path)
    a = p.parse_args()
    expected = {t["table"]: normalized(t) for t in json.loads(a.expected.read_text())["tables"] if t["table"] in CRITICAL}
    actual = {t["table"]: normalized(t) for t in json.loads(a.actual.read_text())["tables"] if t["table"] in CRITICAL}
    failures = []
    for name in sorted(CRITICAL):
        if name not in actual:
            failures.append(f"{name}: missing")
        elif expected[name] != actual[name]:
            for section in expected[name]:
                if expected[name][section] != actual[name][section]:
                    failures.append(f"{name}.{section}: mismatch")
    extras = sorted(set(actual) - CRITICAL)
    if extras:
        failures.append("unexpected critical capture entries: " + ", ".join(extras))
    if failures:
        print("PARITY FAIL")
        print("\n".join(f"- {failure}" for failure in failures))
        return 1
    print(f"PARITY PASS: {len(CRITICAL)} production-critical tables")
    return 0

if __name__ == "__main__":
    sys.exit(main())
