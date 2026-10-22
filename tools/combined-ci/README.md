# Combined one-shot local review transport

Owner authorization 2026-10-22 10:04 UTC: one isolated combined job, no rerun. This preparation is LOCAL ONLY. The parent reviews the exact execution commit and pushes it once to the dedicated branch; then restores the manual-only workflow with a non-force fast-forward. Do not dispatch the old permissions or MariaDB workflows. No default/other-branch write, PR, merge, provider, production, import or deployment authority.

## Scope and immutable inputs

The 301-file candidate is 9deffc32b1466f4187238f6cc72311cd88a5ac9b / tree9a5d66718029497ce980b49ce4b019085d67dea6. Setup builds that candidate's unchanged Dockerfile/locked dependencies once. Both probe and full test containers use the resulting immutable image ID. Exact reviewed probe files, including its original manual workflow and wrapper manifest, are unchanged. New code only orchestrates and gates; no scenario/schema/application/dependency changes.

The full phase re-exports the same301 files into full-work/candidate and creates fresh full-work/candidate-evidence plus distinct socket and named MariaDB data volumes. Both volumes receive independent absence checks; no untracked anonymous database volume is relied upon. Only real full outputs enter that evidence root. Probe synthetic output is never copied into engine evidence. All274 reviewed and26 harness hashes are rechecked. The original full run.sh runs13 scenarios and100 unique/987 assertions once, with29s TERM+1s KILL per scenario,5s lock timeouts, actual sync-victim1213 required; other-victim exits INCONCLUSIVE. A475s whole-run cap includes init, all390s worst-case scenario envelopes and60s regression aggregate. Unused time is not a license to retry or omit tests.

## Budget and clocks

One monotonic clock begins before checkout; Actions' enclosing job timeout is15min (starts slightly earlier, so may cancel earlier). Cumulative ceilings and phase caps:

| Phase | Cap seconds | Elapsed ceiling |
|---|---:|---:|
| Clock + checkout |60|60|
| Exact setup + one PHP build + MariaDB image pull|100|160|
| Complete permissions probe|85|245|
| Probe cleanup|10|255|
| Probe publication + complete host readback gate|20|275|
| Full phase|525|800|
| Independent final cleanup|25|825|
| Final seal + host readback|15|840|
| Artifact upload|60|900|

Total900. Each command receives the smaller phase/global remaining allowance, reserving2s for process-group TERM/KILL. No nested8min+15min workflows. Full start requires at least625s remaining (525+100 finalreserve). After new-container export/security/mount gates, at least510s must remain in the full cap before any DB start: DB startup35 + whole run475. Full preparation therefore has at most15s. Late/slow setup, unsupported mount shapes or insufficient remaining budget STOP before DB, with no truncated test selection. These are hard failclosed caps, NOT measured cold-build completion guarantees. Long-running Docker exec processes are terminated by independently removing the named containers during cleanup.

Artifacts are uploaded by the pinned action only after safe host sealing. Its own1min cap and enclosing15min job bound apply; scheduler/action startup can cause an incomplete/cancelled job and is never represented as success. Final verdict requires parent download + all outer/inner hashes + complete result/cleanup/identities within the authorized job. Parent post-job download is external readback, not the preDB host gate; it cannot be known before the artifact exists. The reviewed diagnostic contract requires local host seal/readback before DB, not preDB external upload/download.

## Security and evidence

Full application container rechecks networknone, uid/gid1000, exact shared hostgroup, zero capabilities, NoNewPrivs, exact readonly source bind, writable evidence, socket volume, and declared+effective vendor tmpfs using the unchanged reviewed parser on fresh inspect/proc. Same image never substitutes for mounts. Host GID1000 is rejected because the privategroup control would be nondistinguishable.

The DB container uses the preexisting exact database.sh setup: initial container root creates the mysql datadir and ci UNIX-socket identity, then mariadbd drops to mysql. It is socket-only/networknone with separate resources; it does NOT claim the application's UID1000/capdrop contract applies to the DB bootstrap. No production/provider credentials are supplied; test auth is local unix_socket. No host elevation or installation.

Complete probe PASS requires actual four denied operations+unchanged metadata/hash, numeric/kernel/hostmount proof, privategroup denial, two producer passes, host consumption, positive/negative publisher branches, original repeated seal/readback, runtime conservation and fresh absence observations. Mount-only PASS is insufficient. The unchanged probe summary's full_job_authorized:false describes its IO_ONLY scope; this controller uses the separately recorded10:04 authorization and does not rewrite that summary.

Cleanup failure cannot produce combined pass. Safely bounded failure evidence may still be sealed/uploaded. No result becomes finalPASS before external readback. Raw-preview validatorNOT_RUN / ONEpreviewSPENT / receiptcrashgapUNKNOWN are unchanged.
