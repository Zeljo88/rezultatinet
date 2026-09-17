# Correction history

## MariaDB 10.11.13 ambiguity fix

- Failing runs: 35221155757, 35221376872, 35221494055.
- Exact terminal error: `ERROR 1052 (23000): Column 'source_updated_at' in UPDATE is ambiguous`.
- Cause: the event upsert compared an unqualified target `source_updated_at` while its `INSERT ... SELECT` joins mapping tables with columns of the same name.
- Change: qualify only the comparison operand as `events.source_updated_at`; no legacy, application, runtime, migration, provider, or production path changed.
- Before SHA-256: `57a89d95d1d814518d7bfc1b7d11116df7c1bfd57cad4b20352df5d83602a4d9`.
- After SHA-256: `ad08b28ea593b7241cde04849430c43f1b087169834085cd7c4361912fa49c14`.
- Workspace source remains unchanged until a complete passing two-cycle run proves this correction.
