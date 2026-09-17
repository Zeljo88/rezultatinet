#!/usr/bin/env python3
"""Generate a data-free Laravel MariaDB schema state from the sanitized A1a manifest."""
from __future__ import annotations
import argparse, json
from collections import OrderedDict
from pathlib import Path


def qi(value: str) -> str:
    return "`" + value.replace("`", "``") + "`"


def qs(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


def column_sql(column: dict) -> str:
    parts = [qi(column["COLUMN_NAME"]), column["COLUMN_TYPE"]]
    if column.get("CHARACTER_SET_NAME"):
        parts += ["CHARACTER SET", column["CHARACTER_SET_NAME"], "COLLATE", column["COLLATION_NAME"]]
    parts.append("NULL" if column["IS_NULLABLE"] == "YES" else "NOT NULL")
    default = column["COLUMN_DEFAULT"]
    if default is not None:
        parts += ["DEFAULT", str(default)]
    if column.get("EXTRA"):
        parts.append(column["EXTRA"])
    if column.get("COLUMN_COMMENT"):
        parts += ["COMMENT", qs(column["COLUMN_COMMENT"])]
    return " ".join(parts)


def render(manifest: dict) -> str:
    lines = [
        "-- Sanitized, data-free MariaDB 10.11 schema state.",
        "-- Generated only from reports/2026-09-16-a1a-schema-manifest.json.",
        "-- The migrations rows below are schema metadata required so Laravel skips unsafe history.",
        "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;",
        "SET FOREIGN_KEY_CHECKS=0;",
        "",
    ]
    for table in manifest["tables"]:
        name = table["table"]
        definitions = ["  " + column_sql(c) for c in table["columns"]]
        indexes: OrderedDict[str, list[dict]] = OrderedDict()
        for idx in table["indexes"]:
            indexes.setdefault(idx["INDEX_NAME"], []).append(idx)
        for index_name, members in indexes.items():
            members.sort(key=lambda item: item["SEQ_IN_INDEX"])
            cols = ", ".join(qi(i["COLUMN_NAME"]) + (f"({i['SUB_PART']})" if i.get("SUB_PART") else "") for i in members)
            if index_name == "PRIMARY":
                definitions.append(f"  PRIMARY KEY ({cols})")
            elif members[0]["NON_UNIQUE"] == 0:
                definitions.append(f"  UNIQUE KEY {qi(index_name)} ({cols})")
            else:
                definitions.append(f"  KEY {qi(index_name)} ({cols})")
        fks: OrderedDict[str, list[dict]] = OrderedDict()
        for fk in table.get("foreign_keys", []):
            fks.setdefault(fk["CONSTRAINT_NAME"], []).append(fk)
        for constraint_name, members in fks.items():
            members.sort(key=lambda item: item["ORDINAL_POSITION"])
            cols = ", ".join(qi(i["COLUMN_NAME"]) for i in members)
            refs = ", ".join(qi(i["REFERENCED_COLUMN_NAME"]) for i in members)
            first = members[0]
            definitions.append(
                f"  CONSTRAINT {qi(constraint_name)} FOREIGN KEY ({cols}) "
                f"REFERENCES {qi(first['REFERENCED_TABLE_NAME'])} ({refs}) "
                f"ON DELETE {first['DELETE_RULE']} ON UPDATE {first['UPDATE_RULE']}"
            )
        for check in table.get("check_constraints", []):
            definitions.append(f"  CONSTRAINT {qi(check['CONSTRAINT_NAME'])} CHECK ({check['CHECK_CLAUSE']})")
        lines += [
            f"DROP TABLE IF EXISTS {qi(name)};",
            f"CREATE TABLE {qi(name)} (",
            ",\n".join(definitions),
            f") ENGINE={table['engine']} DEFAULT CHARSET=utf8mb4 COLLATE={table['collation']};",
            "",
        ]
    ledger = manifest["migration_ledger"]
    values = ",\n".join(f"  ({position}, {qs(row['migration'])}, {row['batch']})" for position, row in enumerate(ledger, 1))
    lines += [
        "-- Laravel schema-state metadata; no application/production rows are included.",
        "INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES",
        values + ";",
        "",
        "SET FOREIGN_KEY_CHECKS=1;",
        "",
    ]
    return "\n".join(lines)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("manifest", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    manifest = json.loads(args.manifest.read_text())
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(render(manifest))

if __name__ == "__main__":
    main()
