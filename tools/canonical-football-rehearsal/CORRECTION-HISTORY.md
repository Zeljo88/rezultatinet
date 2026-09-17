# Correction history

## MariaDB 10.11.13 ambiguity fix

- Failing runs: 35221155757, 35221376872, 35221494055.
- Exact terminal error: `ERROR 1052 (23000): Column 'source_updated_at' in UPDATE is ambiguous`.
- Cause: the event upsert compared an unqualified target `source_updated_at` while its `INSERT ... SELECT` joins mapping tables with columns of the same name.
- Change: qualify only the comparison operand as `events.source_updated_at`; no legacy, application, runtime, migration, provider, or production path changed.
- Before SHA-256: `57a89d95d1d814518d7bfc1b7d11116df7c1bfd57cad4b20352df5d83602a4d9`.
- After SHA-256: `ad08b28ea593b7241cde04849430c43f1b087169834085cd7c4361912fa49c14`.
- Workspace source remains unchanged until a complete passing two-cycle run proves this correction.

## MariaDB initialization handoff retry

- Failing run: 35221656533.
- Exact terminal error after Compose reported healthy: `ERROR 2002 (HY000): Can't connect to local server through socket '/run/mysqld/mysqld.sock' (2)`.
- Cause: the MariaDB image can briefly expose its initialization server as healthy before handing off to the final server.
- Change: add a bounded 30-attempt, one-second local socket query retry after `docker compose up --wait`.
- Before SHA-256: `10137036209f00a1839d76f1fe8f8eb6ac4baa1b4b1409c02a1da48b37ef0c5a`.
- After SHA-256: `d89e49547bb0d30f87e16bc336b22b6a6b72eea473bafe09aa64b8f9d40cb208`.
- No host port, provider call, external endpoint, production path, or fallback was added.
