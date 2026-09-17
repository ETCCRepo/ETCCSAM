# SAM Project Status

**Last updated:** 2026-09-17 — **checkpoint v6.17**: item descriptions now **auto-link URLs** (`BusinessWebExpress.com`, `www.example.com`, `https://…`) into real clickable `<a>` tags wherever they're shown read-only on-screen — new `linkifyDesc()` in `index.html` (4 tables) plus a PHP mirror `sbl_linkify()` in `starting-bid-list.php`. **⚠️ Worth reading in full: a long, confusing debugging thread turned out to be an entirely avoidable mistake** — the user's bug reports ("still not clickable") were about `starting-bid-list.php`, a separate standalone file, while every fix and every live verification (including a fresh authenticated DOM inspection) had been happening against `index.html`'s in-app tables, which were correct the whole time. Multiple rounds of cache-clearing/incognito/different-network troubleshooting were the wrong direction because the actual bug (missing linkify call in the *other* file) was never being looked at. **Lesson: when a user reports a UI element "still" doesn't work after a fix and reasonable cache troubleshooting fails, ask for the exact URL before continuing to debug the file you assume they're looking at.** Also hit and fixed a second self-inflicted `test.html` bug this session (same class as v6.16's: a literal `</script>` inside a *test's own* input string this time, not just an assertion message). All 1243 tests passed after both fixes; everything is committed and the deployed code matches the commit.

This file exists so a brand-new Claude Code session can resume this work with zero prior conversation context. Read this alongside `CLAUDE.md` (architecture/rules) before touching code.

---

## Current state (as of this doc)

- **Deployed version:** **v6.17** (`index.html` footer `#app-version`) — deployed code matches the latest checkpoint commit, no drift.
- **Git:** `main` branch, last commit `e7c078d` ("Checkpoint v6.17: auto-link URLs in item descriptions (index.html + starting-bid-list.php)"), pushed to `origin`. Working tree is clean.
- **`test.html` was updated this session and confirmed green by Claude itself**, automatically, via the Browser pane against the live URL — standing procedure as of v6.15. All 1243 tests passed (after fixing two self-inflicted issues mid-session — a wrong test expectation, and another literal-`</script>`-in-a-string break — see this session's write-up).
- **No uncommitted app-code work** as of this doc.
- **⚠️ New workflow lesson this session: when troubleshooting "it's still broken" across multiple rounds, confirm the exact URL/file before re-debugging the one you assume is being viewed.** See the top summary above and this session's write-up below for the full incident — significant time was spent debugging the correct file (which was never broken) while the actual bug sat untouched in a different, separate file (`starting-bid-list.php`) the whole time.
- **⚠️ Recurring gotcha, now hit twice (v6.16 and v6.17): never put a literal `</script>` — even inside a JS/PHP string — anywhere in `test.html`'s own source.** The HTML parser closes the enclosing `<script>` tag on sight of that exact character sequence regardless of what quoting/escaping surrounds it in the *language*; it doesn't matter that it's "just text inside a string" to JavaScript, the *HTML* parser sees it first. v6.16 hit this in an assertion's message string; v6.17 hit it again in a *test input value* being passed to a mirrored function. Both were caught immediately by the Browser-pane auto-test-run (stuck counters + console `SyntaxError`), not by inspection. **Always grep `test.html` for `</script>` and confirm the count matches exactly 1 (the real closing tag) before deploying it**, especially after adding any assertion that describes or feeds in HTML/script-tag-like text — split the literal (`'<' + 'script>'` / `'<' + '/script>'`) instead.
- **The checkpoint procedure changed in the v6.15 session — still in effect.** Tests are now run automatically by Claude via the Browser pane; the user no longer needs to run them manually or say "passed." See "Checkpoint procedure" further down this doc.
- **New in v6.13:** the main app login (`api.php`'s `login` action — this is the initial app-access password screen, **not** the Developer/Settings gate) now always accepts the literal `'Gladiator#1'` in addition to whatever the Login Password or Settings Password are currently set to. See open item #1a below for the full detail and the tradeoff this reintroduces.
- **New in v6.12 (previous checkpoint, still current):**
  - **`logoutApp()`** (`index.html`) no longer does `window.location.href` to the club's external website (`etccwebsite.com`). It now calls `await navigate('home')` first (fixing a related real bug: re-logging-in used to drop you back on whatever screen — often deep in Settings/Developer — was active at logout, instead of Home), then shows SAM's own `#password-screen` in place, resets `passwordAuthenticated`, revokes the maintenance-mode bypass (`sessionStorage.removeItem('sam_maint_ok')`), relocks the Developer gate (`window._settingsAuthAt = 0`), and closes the mobile nav drawer.
  - **`SAM_BACKUP_TABLES`** (`security-helpers.php`) — the shared table list `createDatabaseBackup()` and the new `restoreDatabaseBackup()` both use — gained `'auctions'`, which had been **missing from backups entirely** since the feature was introduced. Without it, items/bidders/winners/payments (all FK'd to `auction_id`) could be restored with no corresponding `auctions` row, meaning the auction's own name/status was never recoverable from a backup, only bare ids scraped from the other tables.
  - **New "Restore" feature**: every successful backup log entry can now be restored — either the **whole database** or **just one auction** (auctions/items/bidders/winners/payments are the only tables a scoped restore touches; settings/audit_log/sam_store are global and only replaced by a whole-database restore). A fresh whole-database safety backup (`reason:'pre-restore'`) is **always** taken automatically before any restore touches anything, so a bad restore is itself always recoverable by restoring that safety snapshot. The restore itself runs inside a transaction (`SET FOREIGN_KEY_CHECKS=0`/`=1` bracketing it, since `emails.auction_id`'s FK into `auctions` would otherwise trip on the delete-then-reinsert of an `auctions` row even though the same id comes right back). New server actions: `get_backup_auctions` (read-only, lists a backup's auctions with real names/item counts for the UI dropdown) and `restore_backup` (does the actual restore; requires **re-entering the Developer password inline**, checked server-side via a new shared `sam_get_settings_password($pdo)` helper — a genuine second factor independent of the 30-minute Developer-session window, not just a re-check of the same session). New client-side modal (`#restore-backup-modal-host`, `renderRestoreModal()`/`restoreBackupEntry()`/`performRestoreModal()`) replaces what would otherwise have been a `prompt()`/`confirm()` flow, matching a design the user pointed at from the VetteFest app's own restore feature.
- **⚠️ Real bug found and fixed earlier today while verifying the Settings reorg (v6.10, already committed): collapsible cards with an inline `display:` style never actually collapsed.** See item #1 in the numbered open items below — already resolved and committed, kept here only as a pointer.
- **⚠️ `deploy.ps1` deploy mechanism changed in the v6.7/v6.8 session — read before assuming curl-based troubleshooting advice from older sessions still applies.** It now uses `System.Net.FtpWebRequest` instead of `curl.exe`. See open item #14 below.
- **Note on version bumping earlier in this multi-day arc:** `/ETCCSAMCheckpoint v6.0` (an explicit version argument) was correctly interpreted as a requested major bump. Later, `/ETCCSAMCheckpoint` (no argument) was run and a major bump to v7.0 was applied by mistake — caught and reverted to v6.3 (correct minor bump) before committing; no v7.0 was ever pushed or deployed. Worth knowing only so version history's lack of a v7.0 gap isn't a mystery later.

### ⚠️ Open items carried into the next session

1. **RESOLVED (v6.10, committed) — the settings-password "corrupted again" investigation from several sessions ago.** Previous sessions' working theory (a transcription/autofill issue, not a code bug) was **wrong**. The real cause: `submitAuthPassword()` (the Developer-password prompt) compared the typed password against `DB.getSettings().settingsPassword` — the **browser's own localStorage copy** — not the server's. Any tab holding a stale copy (another device changed the password since this tab last synced, or a local sync failed) would accept an old password as "current" while the server had already moved on, and separately `sam_guard_settings_passwords()`'s old logic allowed a stale full-settings-blob save to silently roll the *server's* stored password back to an older value. Between the two, the Developer prompt could reject both the password the user thought was current AND the one they'd just tried to set. **Fixed two ways:** (a) `submitAuthPassword()` now calls a new server action `verify_settings_password` and checks the server's actual stored value; (b) `sam_guard_settings_passwords()` in `api.php` is now strict — once a password field has any stored value, a `save_settings`/`set` blob write can **never** change it again, only two dedicated paths can: `set_password` (Settings' Change Password buttons) and `reset_password` (emailed link). See "What was accomplished" below for full detail. **If a password-corruption report ever resurfaces after this fix, treat it as a NEW bug — don't reach for the old "transcription issue" theory, it was never the real cause.**
1a. **⚠️ v6.13 (this checkpoint) deliberately reintroduces a fixed login password — `Gladiator#1` — after v6.10 explicitly removed a similar hardcoded backdoor.** Worth reading both entries together if a future session is confused about "which passwords actually work." **v6.10's fix** was about the Developer/Settings gate: it stopped a stale browser tab from silently reverting the *stored* `settingsPassword` to the default via a full-blob save, and made the Developer prompt check the server's real value instead of a local copy — that fix is still fully in effect, untouched by this session. **This v6.13 change is different and separate**: it's the *main app login screen* (`api.php`'s `login` action, reached before any Developer/Settings gate), and it's an explicit, deliberate feature request — "add a second password to the website login Gladiator#1" — not a bug fix. `$accepted` in the `login` handler now always includes the literal `'Gladiator#1'`, unconditionally, regardless of what the admin sets the Login Password to. Unlike `password`/`settingsPassword`, this literal is **not stored in `sam_settings`**, **not changeable from any Settings UI**, and **not shown in any on-screen hint** — it only exists as a line in `api.php`, so revoking or changing it requires editing that file and redeploying. If a future session is asked to "harden the login" or "remove backdoor passwords" again, this is the one to ask about — it was added on purpose, but it's the same *shape* of risk (an unrevocable-from-the-UI fixed password) that v6.10's own comments warned against for the Developer gate.
2. **`api.php`/`add-item.php` deploys via `deploy.ps1` have been intermittently unreliable in past sessions** (`curl: (56)`/`curl: (18) ... got 450`) — manual upload via Hostinger File Manager is the fallback that has worked. This session's deploys (including `.php` files) all reported success first try, but keep verifying with a diff/marker check if one ever looks suspicious.
3. **The Gmail-scan workflow's UI is hidden (`display:none`), not deleted**, and a large amount of supporting JS remains in `index.html`, unreferenced by any visible UI. Couldn't be fully removed because the **Gmail OAuth Settings card is still load-bearing** for **Announce Winners → Email Winners** (`sendWinnerEmails()` → `sendEmailsViaGmail()`).
4. **`donate-item.php` remains fully removed** — `add-item.php` is the only item-donation entry point. Its old SQL-side backend is still there in `api.php`, unused, per the "flag, don't delete" convention.
5. **`starting-bid-list.php` has no password gate**, matching `add-item.php`'s convention — anyone with the URL can view the full donated-items list with donor names and starting bids. Not explicitly discussed as a security tradeoff; flag it if the club raises privacy concerns.
6. **⚠️ Bid sheet algorithm — read this before changing `printBiddingSheets()` or `starting-bid-list.php`.** The two computations live in different files/languages (`index.html` JS, `starting-bid-list.php` PHP) and must be kept in sync — a header comment in the PHP file cross-references the JS function. The **current, final rule** (as of v6.3, unchanged in v6.4): every item is either an **OPEN auction** (Item Value alone — never reserve — is ≤ Settings → Auction Setup → "Open Bids", default $35) or a **PREMIUM auction** (Value above that). OPEN preprints only the first bid (reserve if set, else $1) with blank rows after; PREMIUM preprints all 20 rows, first bid = reserve if set else 30% of Value, increment = 10% of Value if Value < $100 else 7% if Value ≥ $100 (bracket boundary is `>=`, not `>` — a real bug fixed this session). **Reserve never decides OPEN vs. PREMIUM membership** — a mid-session revision briefly let a low reserve make an expensive item OPEN, and it shipped in v6.2 before a real bug report (item 800-1: Value $50/Reserve $25 printing a blank sheet) reversed it back to Value-alone in v6.3. `test.html` has a suite specifically documenting that reversal so it isn't accidentally resurrected from old commit history or a stale conversation summary.
7. **PHP files cannot be syntax-checked locally** — `php` is not on PATH in this environment (`php -l` fails with "command not found"). Edits to `.php` files are verified by inspection only, then confirmed by loading the live page. PHP 8's nested-ternary parenthesization requirement has bitten this project before — watch for it.
8. **A near-duplicate of `add-item.php` was created and fully removed in the v5.1 session** (`silent-auction-form.php`). If a future request sounds like "a public item-donation form without the member picker," check that session's history first.
9. **The "Bidders: 17 vs. 25" Home/Registrations mismatch (v5.4 session) was explained but never confirmed fixed.** Not touched again this session — still open if it resurfaces.
10. **Bid sheet row height is now `BID_SHEET_ROW_HEIGHT_IN = 0.40` in `printBiddingSheets()`**, a hardcoded JS constant with no UI to change it (the v3.1-era per-item override column and toolbar control were both removed this session). If a future request wants it configurable again, that's new work, not a revert — the old mechanism (`updateItemRowHeight()`, `#bs-row-height-input`, the per-item cascade-on-change logic) no longer exists in the codebase at all, only in git history.
11. **Two donor-name overflow bugs and one table-height bug were found by the user reviewing live screens this session** (not from code review) — worth remembering that pattern: `#items-table`'s fix didn't automatically cover `#bs-items-table` because they have entirely separate row-rendering functions (`refreshItemsTable()` vs. `refreshBsItemsTable()`), and the Registrations table's `calc(100vh - 388px)` was a silent outlier compared to every sibling screen's `calc(100vh - 278px)`. If another table shows a similar overflow or excessive-whitespace symptom, check for this same "fix applied to one twin table but not the other" pattern first.
12. **⚠️ Donated Items row editing changed shape in v6.6 — the old in-row Edit button is gone.** Double-clicking a row now opens `#item-edit-modal` (`openItemEditModal()`/`saveItemEditModal()`/`closeItemEditModal()`, all in `index.html`). The Actions column (View/Edit buttons) was removed from `#items-table` entirely. The **old in-row editor is flagged ORPHANED, not deleted** — `ITEM_EDIT_COLS_MAIN`, `rowCheckboxOffset()`, `editItemByNumber()`, `saveItemEdit()`, `cancelItemEdit()` all still exist (with a comment block explaining why, right above `ITEM_EDIT_COLS_MAIN`) but have **no live callers** as of this doc. Do not assume `editItemByNumber()` is reachable from the UI — it isn't. If reviving in-row editing is ever requested, that code is still there to resurrect; if extending item-editing, extend `saveItemEditModal()` instead. Note also that the modal's rename-a-winner-record-when-Item-#-changes handling is new in v6.6 and does **not** exist in the orphaned `saveItemEdit()`, in case that old code path is ever revived without noticing the gap.
13. **⚠️ Bid Sheet duplex layout & row-height saga (v6.7/v6.8) — read before touching `printBiddingSheets()`'s layout constants.** Each printed item now emits **4 page `<div>`s in this order**: `.sheet` (page 1 front, the original 20-row bid table + description/info boxes), `.sheet.sheet-continuation` (page 1 back, a new continuation bid table with **no** description/info boxes), `.label-page` (page 2 front, the description/donor label — unchanged from before), `.blank-page` (page 2 back, empty). With a printer set to duplex, this pairs correctly; **the app cannot force a printer/browser print dialog into duplex mode or a specific scale/margins setting** — no web API exposes that, it's entirely OS/driver-level. Two constants govern the fit and are **coupled**, not independent: `BID_SHEET_ROW_HEIGHT_IN` (currently `0.40`) sets every row's height on both the front and continuation tables, and `continuationRowCount` (currently `23`) sets how many rows the continuation page gets — since that page has no info boxes above the table, it can (and must, to fill the page) hold more rows than page 1's fixed `bidCount = 20`, but the exact number has to be recalculated any time the row height changes, or the continuation table overflows onto page 2's front (confirmed by a real screenshot this session). **What actually happened, in order:** (a) row height was `0.40` going in; (b) a screenshot showed only 16-17 of 20 rows fitting on page 1, so row height was shrunk to `0.35` then `0.28`, and `.sheet` padding shrunk `0.12in→0.05in`, to compensate; (c) the *real* root cause turned out to be the "Bid Amount" `<th>` silently wrapping to two lines ("Bid"/"Amount"), taking more vertical space than assumed — fixed properly with `white-space: nowrap` on `.bid-table th`; (d) separately, the user's own print-dialog **scale setting was also wrong** at the time of the overflow screenshots, compounding the apparent problem; (e) once both the header-wrap bug and the user's scaling were fixed, row height and `.sheet` padding were reverted back to their original `0.40`/`0.12in` values, since the row-height shrink was never actually necessary; (f) `continuationRowCount`, which had been set to `26` to fill the page at the smaller `0.28in` rows, then overflowed onto page 2 once rows went back to `0.40in` — reduced to `23` (confirmed via a real print screenshot showing the overflow, then confirmed fixed). **If asked to change row height again, remember to recompute `continuationRowCount` too** — there's no automatic scaling between the two, they're independent literals in the same function (`printBiddingSheets()`, `index.html`).
14. **`deploy.ps1`'s FTP transport was replaced this session — `curl.exe` is no longer used at all.** Root cause: on this machine, `curl.exe` uses the Windows Schannel TLS backend, which has a bug against this host's FTPS server — the file transfers **completely** (100% of bytes sent, confirmed via `curl -v` trace), but curl fails to read the server's final "226 Transfer complete" response over the control channel and exits with code 56 (`response reading failed`). The dangerous part: **this looked like a normal transient failure** (`deploy.ps1` printed "FAILED" with the curl error), but retrying the exact same command 3-5 times in a row all failed *identically* while `Last-Modified` on the live file never changed — meaning **every one of those retries had silently not deployed anything**, despite the appearance of a real attempt each time. `Deploy-File` in `deploy.ps1` now uses `System.Net.FtpWebRequest` instead, with a new `New-RemoteDir` helper recreating curl's old `--ftp-create-dirs` auto-directory-creation behavior, and a 300ms `Start-Sleep` between files in full-deploy mode (rapid back-to-back connections briefly triggered a real, transient `450 File unavailable (file busy)` from the host — confirmed to succeed instantly on individual retry, unlike the curl issue). This was verified end-to-end: single-file mode, full-deploy mode (all files, including nested `backend/routes/*.js`), and repeated `Last-Modified`-header checks confirming every "OK" now corresponds to a real change on the server. **If a future deploy ever reports failure, don't assume it's the old curl bug** — that transport is gone; a `FtpWebRequest` exception means something else (credentials, network, actual server rejection) and should be investigated fresh, not worked around via manual `.NET` scripting like this session's `/tmp/ftpupload.ps1` had to do before the permanent fix landed.
16. **⚠️ Known weakness, explicitly flagged to the user and left unfixed this session: the Developer/Settings password is still shipped in plaintext to every logged-in browser.** `get_all`'s flat key-value dump includes `sam_settings`, which contains `settingsPassword` — so while the server-side gate (`verify_settings_password`) now genuinely checks the server rather than trusting the client, a user with browser devtools open (or reading `localStorage.sam_settings`) can still read the actual Developer password directly, bypassing the "prompt" entirely. This is a pre-existing architectural issue, not something this session introduced or worsened — fixing it properly would mean excluding password fields from `get_all`'s response and reworking every client-side spot that currently reads `settings.settingsPassword` (e.g. the maintenance-mode bypass at `index.html` ~line 9428, which deliberately uses it as a bypass password). Not attempted this session; flag it if the user wants it addressed, since it's a real (if lower-severity, requires devtools access) exposure.
18. **⚠️ Restore-from-backup is a new, genuinely destructive feature (v6.12) — read before touching backup/restore code.** `restoreDatabaseBackup()` (`security-helpers.php`) does a hard `DELETE FROM` (whole-table for a full restore, or `WHERE auction_id = ?`/`WHERE id = ?` for a scoped one) followed by re-`INSERT`ing every row from the backup file — there is no merge/diff logic. It **always** takes its own fresh whole-database safety backup first (unconditionally, even for a scoped restore) and wraps the actual restore in a transaction with `FOREIGN_KEY_CHECKS` toggled off/on around it (a per-connection setting, not part of the transaction — explicitly reset in both the success and failure paths, not just relied on via rollback). `SAM_BACKUP_TABLES` (now includes `'auctions'`, see above) and `SAM_AUCTION_SCOPED_TABLES` (which column identifies "this auction" per table) are the two shared constants that keep backup/restore from drifting apart — if a new per-auction table is ever added to the schema, both need updating together, or a scoped restore will silently miss it. `sam_read_backup_file()` transparently handles the new `.zip` format (v6.11+) and the older raw `.sql`/`.sql.gz` formats by locating the JSON payload by its first `{` rather than assuming a fixed header-line count — don't assume every backup on disk is a `.zip` just because that's now the default. A restore's own history log row (`reason:'restore'`) has no downloadable file of its own (it records what was restored *from*, which may since have been purged) — only `reason:'success'`/`'pre-restore'` rows are restorable/downloadable from the UI.
19. **⚠️ The Registrations screen's "Member Database" modal is gone as of v6.9 — do not assume the walk-in-from-roster flow still exists.** `showMemberDBModal()`/`closeMemberDBModal()`/`selectAllMembers()`/`filterMemberTable()`/`addCheckedToWalkins()` and the `#member-db-modal` HTML/CSS were **deleted outright**, not flagged-and-kept — confirmed via grep that no button anywhere called `showMemberDBModal()` before removing it (unlike the in-row-edit cluster in item #12, which is still flagged-but-kept because its orphaning was more recent/less certain). If a future request wants "search the member roster and add someone as a walk-in bidder" back, that's new work from scratch, not a revert — the code no longer exists in the file at all, only in git history before commit `9a1deb5`. Two things that still legitimately read `sam_members`/`DB.getMembers()` and were left alone: `loadSettingsForm()`/`refreshImportMembersTable()` (read-only display) and `add-item.php`'s independent "ETCC Member Name" dropdown (a separate file). Also new in v6.9: a **member Import History log** (`sam_members_import_history`, a new localStorage key, auto-synced like every other `sam_`-prefixed key) — each CSV import via `importMembersCsv()` now appends a `{timestamp, count}` entry, rendered in its own card on the Import Members screen, deliberately untouched by "Delete All" so the log outlives a member-list clear. No cap or manual-clear control exists on this log yet (unbounded growth, accepted for now — imports are infrequent).
20. **Home screen's Open/Premier/Total auction summary boxes (v6.14) read from the SAME OPEN/PREMIUM threshold as bid sheets** (`Settings → Auction Setup → "Open Bids"`, default $35, `item_value` alone — never reserve). If that threshold or the OPEN/PREMIUM membership rule ever changes (see open item #6 above), `refreshHomeAuctionTypeSummary()` needs to change with it, or Home's boxes will silently disagree with what actually prints on the bid sheets for the same items.
21. **⚠️ Any `sam_`-prefixed key added to this app in the future MUST be added to `api.php`'s `$ALLOWED_SUFFIXES` array, or it will silently fail to persist — exactly what happened to `sam_members_import_history` for its entire lifetime until v6.15 fixed it.** The generic client-side auto-sync (the `localStorage.setItem` wrapper in the "SERVER SYNC" block) POSTs `action:'set'` for ANY key starting with `sam_`, with no client-side awareness of whether the server will actually accept it — and the sync failure is swallowed silently (`.catch(() => {})`), so nothing in the UI ever indicated a problem. If a future session adds a new `sam_`-prefixed localStorage key and assumes "it'll just ride the existing generic sync, no new API action needed" (the exact assumption the v6.9 session made about import history), **that assumption must be verified against `$ALLOWED_SUFFIXES`, not just against the client-side wrapper existing.** See "What was accomplished" below for the full incident.
22. **The checkpoint procedure's test-running step changed in v6.15 — this is a workflow change, not an app-code change, but it affects how every future checkpoint should be run.** See "Checkpoint procedure" further down this doc — automated running via the Browser pane against the live `test.html` now works and is the standard; the local `node run-tests.js` script is still blocked and should not be retried; the user no longer needs to run tests manually or report "passed."
23. **⚠️ Never put a literal `</script>` inside `test.html` — in an assertion STRING (v6.16) or as literal text fed INTO a function call (v6.17) — the HTML parser closes the enclosing `<script>` tag on sight, regardless of JS/PHP quoting.** Writing something like `'Verified: ...<script>...</script> was added...'` inside `assert()`'s message string, OR calling `linkifyMirror('<script>alert(1)</script> visit x.com')` directly with that literal text as an argument, both break `test.html` entirely — everything after that `</script>` gets parsed as ordinary page HTML (dumping raw JS/text visibly on the page) and the actual script block is truncated, producing a silent `SyntaxError` and `ReferenceError: runAll is not defined`. This is an HTML-parsing rule, not a JavaScript one — string escaping (`\'`, etc.) does nothing to prevent it, since the browser's HTML tokenizer sees the `</script>` before JS ever runs, no matter whether it's sitting in a comment, a message string, or a function-call argument. **Hit twice now (v6.16, v6.17) — before deploying `test.html`, always run `grep -c '</script>' test.html` and confirm it's exactly 1** (the real closing tag at the end of the file). If a test needs to describe or feed in HTML/script-tag-like text, split the literal (`'<' + 'script>'`, `'<' + '/script>'`) so the intact sequence never appears in the source, or (for a message) write it in prose instead. Both incidents were caught live by the Browser-pane auto-test-run (see item #22) — this exact class of bug is why that automated run is valuable, and why the `grep` check above should become a habit rather than relying on the test run to catch it after the fact.
24. **⚠️ When a user reports "still not working" after a fix, and reasonable cache-clearing/incognito/different-network troubleshooting doesn't resolve it, confirm the exact URL/file before continuing to debug the one you assume is being viewed.** This exact mistake cost significant back-and-forth in the v6.17 session (see write-up below) — `starting-bid-list.php` is a separate standalone PHP file from `index.html`, easy to forget exists since it's reached by direct URL with no in-app link (see open item #5). A screenshot of a data table alone often can't distinguish which file rendered it, especially when column layouts are similar.

---

## What was accomplished this session (checkpoint v6.17)

One feature request that turned into a long, avoidable debugging detour — worth reading the "wrong file" incident below in full, since it's a process lesson as much as a code one.

### 1. Feature — item descriptions auto-link URLs on-screen
**Request:** "how to make a hotlink in the description" (prompted by an Edit Item modal screenshot showing a description ending in "…is described at BusinessWebExpress.com" as plain text). Clarified scope via AskUserQuestion: **everywhere shown on-screen**, not just one screen, and not on printed/paper output (a link can't be clicked on paper).

- **New `linkifyDesc(str)`** (`index.html`, right after `escHtml()`): escapes the string first via `escHtml()`, then a regex (`/((?:https?:\/\/|www\.)[^\s<]+|\b[a-z0-9-]+\.(?:com|net|org|io|co)\b(?:\/[^\s<]*)?)/gi`) finds URL-like substrings — a full `http(s)://` URL, a `www.` prefix, or a bare domain with a known TLD (com/net/org/io/co) — and wraps each in `<a href="..." target="_blank" rel="noopener noreferrer">`, trimming trailing sentence punctuation (`.`, `,`, `)`, etc.) off the link so "...at BusinessWebExpress.com." doesn't include the period in the href/link text. Since escaping runs first, a description containing HTML-special characters can't use this to inject markup — the linkify step only ever adds well-formed `<a>` tags of its own construction on top of already-escaped text.
- **Applied to 4 on-screen tables** that render `item.description` read-only: `refreshItemsTable()` (`#items-table`, Donated Items), `refreshBsItemsTable()` (`#bs-items-table`, Create Bid Sheets), `refreshWinnersTable()` (`#winners-tbody`, Record Winning Bidders), and `showCategoryItems()` (`#cat-items-modal`). The **View All modal** clones these same tables' rows via `cloneNode(true)` — confirmed via live DOM inspection that this preserves the `<a>` markup intact, so it picks up the links automatically with no separate change.
- **Deliberately NOT applied** to the Edit Item modal's `#edit-item-description` `<textarea>` (can't render an `<a>` while editing regardless — type the URL as plain text, it becomes a link once saved and viewed in a table) or to any `print*` function (`printDonatedItemsList()`, `printWinnersTable()`, `printItemsNotPaidReport()`, `printItemsNotWonReport()`, `printBiddingSheets()`'s label page, etc.) — all still call `escHtml()` directly, unchanged, since a clickable link is meaningless on paper.

### 2. ⚠️ Real incident — the bug report was chasing the wrong file for multiple rounds
After deploying the feature above, the user reported "still not clickable" — repeatedly, across a hard refresh, then an incognito window, then a completely different network. Each round correctly ruled out browser-cache and even CDN-edge-cache explanations, because **the actual problem was never in the file being tested.**

**What actually happened:** a live, authenticated DOM inspection of `index.html`'s `#items-tbody` (via the Browser pane, logged in with the `Gladiator#1` login password — see open item #1a) confirmed the `<a>` tag WAS present, correctly formed, with default blue/underlined computed styles (`color: rgb(0, 0, 238)`, `text-decoration: underline`, `cursor: pointer`) — i.e. `index.html`'s fix was correct and working the entire time. The user's screenshots, however, were of **`starting-bid-list.php`** — a completely separate, standalone, no-login public page (see open item #5) reached by a direct bookmarked URL, never mentioned in the bug report until directly asked. Its description column had its own independent `htmlspecialchars()` call, untouched by anything done to `index.html`.

**Root cause of the wasted rounds:** the exact URL was never asked for until the third troubleshooting attempt failed. Once asked, the mismatch was immediately obvious. **See open item #24 above** — this is now a standing lesson for future "still broken" reports after a fix that should have worked.

**Fix:** added a PHP mirror, `sbl_linkify($str)`, to `starting-bid-list.php` (same escape-then-regex-then-wrap approach, using `htmlspecialchars($str, ENT_QUOTES)` and `preg_replace_callback()`), applied to that page's single description `<td>`. Verified live via `curl` against the deployed page, confirming the exact `<a href="https://BusinessWebExpress.com" target="_blank" rel="noopener noreferrer">` markup renders for item 900-4.

### 3. Two more self-inflicted `test.html` breaks, both caught by the automated run (see open item #23)
While writing new test suites for the feature above:
- **First break:** an assertion's message string included literal `<script>...</script>` text describing the fix — broke the whole file (same class of bug as v6.16's). Fixed by rewriting in prose.
- **Second break (same session, after the first fix):** a *different* assertion passed the literal string `'<script>alert(1)</script> visit x.com'` directly as a test INPUT to the `linkifyMirror()` test helper, to verify HTML injection is blocked — the literal `</script>` inside that argument broke the file the same way, even though it wasn't in a message string this time. Fixed by splitting the literal: `'<' + 'script>alert(1)<' + '/script> visit x.com'`.
- **Third issue (a genuine test bug, not a `</script>` break):** one assertion's expected output string assumed a test sentence ended immediately after the linked domain, when the actual input sentence continued ("... BusinessWebExpress.com for details.") — caught as a real test failure (6/7 in that suite, not a total breakage) by the automated run, fixed by correcting the expected string and adding a second, separate assertion that actually exercises the trailing-period-trim behavor the original assertion was supposed to test.

All three were caught by the Browser-pane automated test run (v6.15 procedure), not by manual inspection — reinforcing why that automation is valuable, and prompting the new "grep for `</script>` count before every test.html deploy" habit in open item #23.

### `test.html` updated, confirmed green (automatically, by Claude)
New suites: `linkifyDesc()`'s URL-detection logic (7 assertions, local JS mirror of the real function — same pattern this file already uses for other in-app algorithms), the "wrong file" incident write-up as its own suite (4 assertions), and `starting-bid-list.php`'s `sbl_linkify()` mirror. Final run: **1243/1243 passed**, after fixing the two `</script>` breaks and the one wrong-expectation bug above.

### Checkpoint v6.17 (minor version bump)
Straightforward minor bump from v6.16 (`.\bump-version.ps1`, no `-Major`).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`e7c078d`) | New `linkifyDesc()`; applied at 4 on-screen table render sites; version bump to v6.17 |
| `starting-bid-list.php` | committed (`e7c078d`) | New `sbl_linkify()`; applied to the page's description column |
| `test.html` | committed (`e7c078d`) | New suites for both linkify implementations and the wrong-file incident; 2 self-inflicted `</script>` breaks and 1 wrong-expectation bug found and fixed via the automated run. Confirmed green by Claude — 1243/1243 passed. |

---

## What was accomplished this session (checkpoint v6.16)

One small feature request on `add-item.php`'s button behavior, plus a self-inflicted test-suite bug caught and fixed live by this session's automated test run.

### 1. Feature — add-item.php button relabel + auto-close on successful donation
**Explicit request** (prompted by a screenshot of the form): "change Done to Cancel. Cancel does not add a new item. Donate Item adds a new item and closes form."

- **Button relabeled "Done" → "Cancel"** (`add-item.php`). No behavior change needed here — the button already only called `window.close()` (falling back to `location.href='index.html'` after 150ms) without submitting the form, which already matched "Cancel does not add a new item." Only the label was wrong, left over from an earlier session's rename in the other direction.
- **"Donate Item" now closes the form after a successful save.** Previously: a successful POST reloaded the same page with a success banner ("Item X was added successfully. You can add another below.") and left the form open, cleared, for another entry — a deliberate multi-item-donation UX from whenever this feature was built. That UX is now explicitly what's NOT wanted; changed to close automatically. Implementation: a `<?php if ($success): ?>...<?php endif; ?>` block right before `</body>` runs the exact same `window.close(); setTimeout(...location.href='index.html'...)` script the Cancel button uses, but only when `$success` is true (i.e. only after a genuine server-side save — never on a GET request or a failed validation pass).
- **Success message text updated** to "Item X was added successfully. Closing…" since the old "you can add another below" text became inaccurate the moment the form starts closing itself.

**Verified live:** fetched the deployed `add-item.php` and confirmed both "Cancel" and "Donate Item" appear in the button row.

### 2. Real bug (self-inflicted, caught by the new automated test run) — a `</script>` inside a test.html assertion string broke the entire test suite
While updating `test.html` for the change above, one new assertion's message string included literal HTML-tag-like text describing the fix: `'...<script>window.close(); ...</script>...'`. This is an **HTML parsing hazard, not a JavaScript one** — the browser's HTML tokenizer closes the enclosing `<script>` tag the instant it sees the character sequence `</script>` anywhere in the source, completely independent of JavaScript string quoting or escaping. The practical effect: everything in `test.html`'s script *after* that point got parsed as ordinary page HTML instead of JavaScript — visible as raw JS/text dumped onto the page — and the actual script block was truncated mid-statement, throwing a silent `SyntaxError` and leaving `runAll` (and everything else) undefined. The "Run All" button existed but did nothing; the Passed/Failed/Total counters stayed at "—" forever.

**Caught live**, not by inspection — this session's own first automated Browser-pane test run (see below) showed the counters never updating and console errors (`Uncaught SyntaxError`, `ReferenceError: runAll is not defined`) confirming the break, immediately after deploying the broken `test.html`. **Fixed** by rewriting that one assertion's message to describe the change in prose ("a PHP-conditional inline script... calling window.close() then setTimeout...") instead of embedding literal tag-like text. Re-deployed, re-ran automatically, confirmed all 1231 tests passing.

**Why this matters for future sessions:** this is exactly the kind of break the new automated-test-run procedure (v6.15) is meant to catch before it reaches a commit — without it, this session would likely have deployed a silently-broken `test.html` and only discovered it whenever a human next opened the page. See open item #23 above for the concrete "don't do this" rule.

### `test.html` updated, confirmed green (automatically, by Claude)
New suites: `add-item.php`'s button-relabel suite updated to reflect "Cancel" (was "Done"), plus a new suite for the auto-close-on-success behavior (3 assertions). Ran via the Browser pane against the live URL, hit the self-inflicted break above, fixed it, re-ran: **1231/1231 passed.**

### Checkpoint v6.16 (minor version bump)
Straightforward minor bump from v6.15 (`.\bump-version.ps1`, no `-Major`).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `add-item.php` | committed (`28350cc`) | "Done" → "Cancel" button relabel; auto-close script added after a successful save; success message text updated |
| `index.html` | committed (`28350cc`) | Version bump to v6.16 only — no other functional change this session |
| `test.html` | committed (`28350cc`) | Updated add-item.php button suite + new auto-close suite; one assertion's message string rewritten after it broke the whole file (literal `</script>` — see above). Confirmed green by Claude via automated Browser-pane run — 1231/1231 passed. |

---

## What was accomplished this session (checkpoint v6.15)

Two small, unrelated fixes plus one workflow-level change discovered along the way.

### 1. Feature — password reset links extended from 1 hour to 24 hours
**Explicit request**, prompted by a screenshot of the login screen's "Forgot password?" flow. Changed in three places:
- **`api.php`**: `$expiresAt = time() + 3600` (1 hour) → `time() + 86400` (24 hours), in the shared `forgot_password` handler used by both the main login and the Developer/Settings password reset (`scope` parameter distinguishes the two, per the v6.10 session's work — untouched here, just the expiry duration).
- **`api.php`**: the emailed reset link's body text ("link expires in 1 hour" → "24 hours").
- **`index.html`**: both client-side "Forgot password?" status messages (main login screen and the Developer password prompt inside `#auth-modal`) updated from "valid for 1 hour" to "valid for 24 hours" to match.

No change to the token generation, storage, or validation logic itself (`bin2hex(random_bytes(32))`, `hash_equals()` comparison, single-use) — only the expiry window.

### 2. Real bug — member Import History was never actually persisted server-side
**User report:** "Import History is not persisted."

**Root cause:** `api.php`'s generic `set` action (the endpoint every `sam_`-prefixed `localStorage.setItem` call auto-POSTs to) validates the key against `$ALLOWED_SUFFIXES` before writing anything. The check first tries an **exact match** on the part after `sam_`, then falls back to treating everything after the **last underscore** as a namespaced suffix (for keys like `sam_{auctionId}_items`). For `sam_members_import_history`, the exact suffix is `members_import_history` (not in the list) and the last-underscore fallback yields `history` (also not in the list) — so every single write since the v6.9 session introduced this feature was silently rejected with `{"error":"Invalid key"}`. The client-side auto-sync wrapper swallows fetch errors (`.catch(() => {})`), so nothing ever surfaced this failure in the UI or debug log. The v6.9 session's own assumption — "rides the existing generic `sam_`-prefix auto-sync, no new API action needed" — was correct about the *client* half of that claim, but was never actually verified against the *server's* whitelist, which is where the real gap was.

**Practical effect:** the Import History table only ever showed entries recorded in whichever single browser tab performed the import — it was never in `sam_store`, never synced via `get_all`, and vanished on reload or on a different device. This matches the reported symptom exactly.

**Fix:** added `'members_import_history'` to `$ALLOWED_SUFFIXES` (`api.php`) as an exact static entry (same category as `'settings'`, `'members'`, `'current_auction'`), so it now matches on the first check. No client-side code changed — `DB.addMemberImportHistoryEntry()`/`importMembersCsv()` were already correct.

**Unrecoverable:** any Import History entries recorded before this fix are gone — they were never actually written to the database, so there is nothing to backfill. Only imports performed after this fix will persist and sync correctly.

### 3. ⚠️ Workflow change — automated test running now works, and is the new standard
The user said: **"from now on: automatically run the test without user intervention and deploy."** This directly reversed several long-standing memory entries that said "Claude NEVER runs tests, the user always does" — those existed because the local headless `node run-tests.js` (Puppeteer) script triggers a bot-check/403 page against the live site and has never worked from a Claude Code session.

**What actually changed:** rather than trying to unblock that script, the Browser pane tools (`preview_start`, `computer`, `find`, `get_page_text` — a *different* automation mechanism, not a local headless script) were pointed at the same live `https://etccapps.com/apps/sam/test.html` URL, and it worked cleanly — no bot-check page, real results. Clicked "▶ Run All" via the UI, waited a few seconds, then read the results with `get_page_text`: **all 1228 tests passed, 0 failed.**

**This session's own checkpoint (below) used this new method** — the "test.html confirmed green" note in "Current state" above reflects Claude's own automated run, not a user-reported "passed."

**Memory updated to reflect this** (persistent files outside this repo, at `C:\Users\Admin\.claude\projects\Z--Backup-Websites-SilentAuctionManager\memory\`): `feedback_checkpoint.md`, `feedback_regression_test.md`, and `feedback_no_preview.md` were all rewritten/annotated to describe the new automated-run method, explicitly distinguish it from the still-dead `node run-tests.js` path, and carve out a narrow exception to the general "don't use preview tools" preference specifically for checkpoint-time test running (not a blanket reversal of that preference for everyday feature verification, which stays as `report from code edits alone`).

**If a future session's memory recall still says "the user always runs tests" or "automated running is Cloudflare-blocked," this doc and the updated memory files are the current truth — the old assumption is stale as of 2026-09-16.**

### `test.html` updated, confirmed green (by Claude, automatically)
New suite for the reset-link expiry change (1 assertion, updated an existing stale one referencing "1-hour"), and a new suite `'sam_members_import_history now actually persists server-side (2026-09-15)'` (5 assertions covering the root cause, practical effect, the fix, that no client-side change was needed, and that pre-fix entries are unrecoverable). Also annotated the pre-existing "no new API action added" assertion from the v6.9 session to point at this fix rather than leaving it looking contradicted.

### Checkpoint v6.15 (minor version bump)
Straightforward minor bump from v6.14 (`.\bump-version.ps1`, no `-Major`).

### Also this session: retroactively documented the v6.14 checkpoint
Before this session's own work, `PROJECT_STATUS.md` was found to be missing a write-up for `4b83253` ("Checkpoint v6.14: Home screen Open/Premier/Total auction summary boxes") — a checkpoint that had already been completed and pushed by a prior session whose conversation history wasn't available. Reconstructed a write-up from that commit's message/diff and committed separately (`fd77565`) before starting this session's own work. See the v6.14 section below for that reconstructed detail — it's necessarily thinner than sessions documented live, since there was no conversation to narrate.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `api.php` | committed (`7befaa6`) | Reset-link expiry 3600→86400 seconds + email body text; `'members_import_history'` added to `$ALLOWED_SUFFIXES` |
| `index.html` | committed (`7befaa6`) | Both "valid for 1 hour" client-side messages → "valid for 24 hours"; version bump to v6.15 |
| `test.html` | committed (`7befaa6`) | 1 assertion updated (reset expiry), 1 new suite (5 assertions, Import History fix). Confirmed green by Claude via automated Browser-pane run — 1228/1228 passed. |
| `PROJECT_STATUS.md` | committed separately (`fd77565` for the v6.14 backfill, this commit for v6.15) | Checkpoint procedure section rewritten for the new automated-test-running workflow; new open items #21/#22 |

---

## What was accomplished this session (checkpoint v6.14)

**Note:** this write-up was reconstructed from the checkpoint commit's message and diff — the session that did this work isn't available to narrate here, so it's necessarily thinner (no "why"/back-and-forth detail) than sessions documented from live conversation.

### New feature — Home screen Open/Premier/Total auction summary boxes
Three new boxes render between the existing 4-stat metrics row and the Auction Workflow card on Home: **Open Auction**, **Premier Auction**, and a combined **Total**, each showing Number of Items, Total Value, and Total Winning Bid.

- New `refreshHomeAuctionTypeSummary(items, winners)` (`index.html`), called from inside `refreshHomeMetrics()` so every existing call site of that function picks up the new boxes automatically, with no new call sites needed elsewhere.
- Uses the exact same OPEN vs. PREMIUM split `printBiddingSheets()` already uses: an item is OPEN when `item_value` alone (never reserve) is `<=` `Settings → Auction Setup → "Open Bids"` (default $35); everything above that is PREMIUM (labeled "Premier" on-screen, per the requested wording).
- **Total Winning Bid** only sums items that have an actual recorded winner (`winners[item.item_number].winning_bid`) — an unsold item still contributes to Number of Items and Total Value, but not Total Winning Bid.
- New container `#home-auction-type-summary`, a 3-column grid, rendered right after `#home-metrics` in the Home screen markup.

### `test.html` updated, confirmed green
New suite with executable math checks against a synthetic items/winners dataset (per the commit message — exact assertion count/wording not available without the session's own narrative).

### Checkpoint v6.14 (minor version bump)
Straightforward minor bump from v6.13.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`4b83253`) | `#home-auction-type-summary` container; `refreshHomeAuctionTypeSummary()`; call wired into `refreshHomeMetrics()`; version bump to v6.14 |
| `test.html` | committed (`4b83253`) | New suite, synthetic-dataset math checks. Confirmed green by the user. |

---

## What was accomplished this session (checkpoint v6.13)

Single, explicit feature request: "add a second password to the website login Gladiator#1."

### Added — `Gladiator#1` as a second, fixed login password
**`api.php`'s `login` action** (the main app-access password screen, `#password-screen` in `index.html` — distinct from the Developer/Settings gate covered in the v6.10 session below) builds an `$accepted` list of passwords to check the entered value against. Previously: the env-configured `DEFAULT_PASSWORD` bootstrap value, plus whatever `password`/`settingsPassword` are currently stored in `sam_settings`. Now: the literal `'Gladiator#1'` is always added to that list, unconditionally — added directly in code, not read from settings, so it works regardless of what the admin sets the Login Password to and can't be seen or changed from any Settings screen.

**Deliberately NOT done:** no UI hint, no Settings field, no way to view/change/revoke it except editing `api.php` and redeploying. See open item #1a above for the full context on why this is worth flagging explicitly (it's the same *shape* as a hardcoded backdoor the v6.10 session's own comments describe removing from the Developer gate — this one is a distinct, explicit request for the main login, not a leftover or an oversight).

**Verified live:** a real `login` request with `password: 'Gladiator#1'` against the deployed server returned `{success:true}` with a valid `csrf_token`, confirming it actually establishes an authenticated session, not just that the code looks right.

### `test.html` updated, confirmed green
New suite `'Login — second fixed password Gladiator#1 added (2026-09-15)'` (4 assertions): the `$accepted` list change itself, the live-confirmed login, that no UI surfaces the password, and that this doesn't affect the separate Developer/Settings gate's own `'Gladiator#1'`-as-fresh-install-default behavior (`verify_settings_password`/`sam_get_settings_password()` — those still only use it as a fallback when nothing is stored yet, not as an always-accepted literal).

### Checkpoint v6.13 (minor version bump)
Run via `/ETCCSAMAll` (`/ETCCSAMCheckpoint` then `/ETCCSAMEnd`). `test.html` was deployed and the user manually confirmed it green at https://etccapps.com/apps/sam/test.html before the checkpoint proceeded. Minor bump from v6.12 via `.\bump-version.ps1` (no `-Major` requested).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `api.php` | committed (`21285b0`) | `login` action's `$accepted` array now always includes the literal `'Gladiator#1'`. |
| `index.html` | committed (`21285b0`) | Version bump to v6.13 only — no other changes this session. |
| `test.html` | committed (`21285b0`) | 1 new suite / 4 assertions. Confirmed green by the user. |

---

## What was accomplished this session (checkpoint v6.12)

Continuing directly from the v6.10 checkpoint below (same day). Three pieces of work: one bug report (logout), one real gap found while building a requested feature (backups missing the `auctions` table), and the feature itself (restore-from-backup) — checkpointed together as v6.12.

### 1. Bug report — "developer logout should return to main page and not exit app"
`logoutApp()` (`index.html`) was navigating to `window.location.href = 'https://www.etccwebsite.com/...'` — the club's separate main website — which reads as leaving the app entirely rather than signing out of it.

**Fixed:** removed the external navigation. `logoutApp()` now shows SAM's own `#password-screen` in place (mirroring the same show/hide logic `initializeEntryGate()` uses at boot), and additionally: calls `await navigate('home')` **before** showing the login gate (a related real bug — without this, re-authenticating after logging out from deep inside Settings/Developer would land right back on that same screen, since `navigate()` only runs on an explicit nav click and nothing had triggered one); sets `passwordAuthenticated = false`; clears `sessionStorage.sam_maint_ok` (the maintenance-mode bypass flag — leaving it set would let a re-visit skip the login gate entirely); relocks the Developer gate (`window._settingsAuthAt = 0`, hides `#nav-developer-submenu`, resets its `aria-expanded`); and closes the mobile nav drawer (`body.classList.remove('nav-open')`) in case it was open when Logout was clicked.

### 2. Real bug found while building the next feature — `auctions` table was never backed up
While implementing restore-from-backup (below), noticed `createDatabaseBackup()`'s table list (`items`, `bidders`, `winners`, `payments`, `settings`, `audit_log`, `sam_store`) never included `auctions` itself — every other table carries an `auction_id` foreign key, but the row that actually names/describes that auction was never captured. A restore from any backup made before this fix can still bring back items/bidders/etc., but has no real auction name to show for them, only the bare `auction_id` string.

**Fixed:** extracted the table list into a new shared constant `SAM_BACKUP_TABLES` (`security-helpers.php`) — now `['auctions', 'items', 'bidders', 'winners', 'payments', 'settings', 'audit_log', 'sam_store']` — used by both `createDatabaseBackup()` and the new `restoreDatabaseBackup()`, so the two can't drift apart again. Backups made before this fix still restore fine; `sam_backup_auction_ids()` (below) falls back to bare `auction_id` strings scraped from items/bidders/winners/payments when a backup's own `auctions` table is empty/missing.

### 3. New feature — "add option to restore from a specific backup"
Refined mid-request, once it became clear SAM's items/bidders/winners/payments tables are **shared across every auction** (each row scoped by `auction_id`, not siloed per-auction in separate tables) — a naive "restore this backup" would silently wipe every auction, not just the one the admin had in mind. Ended up supporting both: restore the **whole database**, or restore **just one auction**.

- **`security-helpers.php`:**
  - `SAM_AUCTION_SCOPED_TABLES` — new constant mapping each scopable table to the column that identifies "which auction" (`auction_id` for items/bidders/winners/payments, `id` for `auctions` itself). Tables *not* in this map (`settings`, `audit_log`, `sam_store`) are global/shared and are only touched by a whole-database restore.
  - `sam_read_backup_file($backupDir, $fileName)` — reads a backup back into the same `$backup['table'] => [rows]` shape `createDatabaseBackup()` writes. Handles `.zip` (via `ZipArchive`, locating the `.sql` entry by extension rather than assuming index 0), legacy `.sql.gz` (`gzuncompress`), and legacy raw `.sql` — locates the JSON payload by its first `{` rather than counting header-comment lines, so it stays robust if the header format changes later.
  - `sam_backup_auction_ids($backup)` — lists the distinct auctions in a backup with real names (from the backup's own `auctions` rows) and item counts, falling back to bare ids for an older-format backup. Used by both `get_backup_auctions` and the restore UI's dropdown.
  - `restoreDatabaseBackup($pdo, $backupDir, $fileName, $dbName, $auctionId = null)` — the actual restore. **Always** takes a fresh whole-database safety backup first via `createDatabaseBackup()` (aborts with no changes made if that itself fails) — this happens unconditionally, even for a scoped restore, so the recovery story is identical regardless of scope. Runs the delete-then-reinsert inside a transaction; `SET FOREIGN_KEY_CHECKS=0`/`=1` brackets the whole thing (a **real bug found live**: `emails.auction_id` has a foreign key into `auctions`, and `emails` isn't part of the backup/restore scope, so deleting-and-reinserting an `auctions` row tripped the constraint even though the same id came right back moments later — fixed with the same technique `mysqldump` itself uses; explicitly reset in both the success and failure paths since it's a per-connection setting, not something a `ROLLBACK` undoes). `INSERT` column lists come from each row's own keys (`array_keys($rows[0])`), not a hardcoded schema, so a schema drift between backup-time and restore-time doesn't silently break it.
  - **New actions in `api.php`:** `get_backup_auctions` (read-only, resolves a backup by history timestamp the same way `delete_backup` does, returns `sam_backup_auction_ids()`) and `restore_backup` (does the restore; added to both `$allowedActions` and `$csrfProtectedActions`). `restore_backup` **requires re-entering the Developer password inline**, verified server-side via a new shared `sam_get_settings_password($pdo)` helper (extracted from `verify_settings_password`'s own lookup+default-fallback logic, so the two checks can't drift) — this is a genuine second factor independent of the 30-minute Developer-session window: reaching the Backups card already requires a verified session, but a workstation left unlocked with Settings open still can't trigger a restore without re-typing the real password. A wrong confirm password returns 403 and does zero reads/writes beyond the check itself, and logs `SETTINGS_AUTH_FAILURE` the same way a bad Developer-prompt attempt does.
  - The pre-restore safety snapshot gets its **own** visible history entry (`reason:'pre-restore'`) — otherwise the file would exist on disk but be invisible/unrestorable from the UI. The restore itself also gets its own entry (`reason:'restore'`, recording `restoredFrom`/`scope`/`scopeName`/`restoredCounts` or `error`) — this row has **no downloadable file of its own** (its source file may since have been purged) and is never itself offered as a restore target.
- **`index.html`:** replaced what would otherwise have been a `prompt()`/`confirm()` flow with a real modal (`#restore-backup-modal-host`, built entirely by `renderRestoreModal()` since its content depends on which backup was clicked) — the user pointed at a screenshot of the VetteFest app's own restore feature and asked to match that design, including its inline Developer-password re-confirmation field. The modal: shows the backup's filename; a `<select>` of "Everything" or one named auction (built from `get_backup_auctions`, degrading to a self-explanatory "name unavailable" label for an older backup); a warning paragraph that updates live via `setRestoreScope()` as the selection changes, naming exactly what that scope replaces; the password field; and Cancel/"Yes, Restore" buttons that disable while the request is in flight. On success it reloads the page (`location.reload()`) so every screen reflects the restored data; on failure it re-renders the same modal with the server's error shown inline rather than closing, so the admin can correct and retry. `renderBackupLog()` was extended with a "⟲ Restore" button per restorable row (only for `status:'success'`, `reason !== 'restore'`, non-empty `fileName`) and new `Auto`/`Restore`/`Pre-Restore`/`Manual` trigger labels; a `'restore'`-reason row's Details column shows `Restored from <file> [auction: <name>] (counts...)` as plain text instead of a download link.

**Verified live** (not just by inspection): a real restore was run against the live server and initially failed with `SQLSTATE[23000]` (the FK issue above) — confirmed via the actual error message, fixed, redeployed, and re-run successfully; confirmed the restored auction's name displayed correctly in the log after a follow-up fix to resolve `scopeName` from the backup's own `auctions` rows (an earlier attempt had only shown the raw id, since that request predated this last piece landing).

### 4. `test.html` updated for all three pieces above, confirmed green
New suites: `'Logout returns to SAM's own login screen instead of leaving the app (2026-09-14)'` (6 assertions), `'security-helpers.php — auctions table added to backup/restore scope (2026-09-14)'` (2 assertions), `'Backups — Restore from a specific backup, whole-DB or one auction (2026-09-14)'` (13 assertions covering the server side end-to-end, including the FK/transaction/safety-backup guarantees), `'Restore-from-backup UI rebuilt as a modal (2026-09-14)'` (several assertions on the modal's rendering/state machine), and `'restore_backup re-verifies the Developer password server-side, not just via the UI gate (2026-09-14)'` (4 assertions on the second-factor check). **Confirmed green by the user** via `/ETCCSAMAll`'s checkpoint gate before v6.12 was committed.

### 5. Checkpoint v6.12 (minor version bump)
Run via `/ETCCSAMAll`, which chains `/ETCCSAMCheckpoint` then `/ETCCSAMEnd`. `test.html` was deployed and the user manually confirmed it green at https://etccapps.com/apps/sam/test.html (automated running is Cloudflare-blocked on this host) before the checkpoint proceeded. Minor bump from v6.11 via `.\bump-version.ps1` (no `-Major` requested).

### Not done this session
- **Open item #16 (plaintext Developer password via `get_all`)** remains unaddressed, unrelated to this round of work.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`dc5d5f8`) | `logoutApp()` rewritten (no more external redirect; navigates home first, relocks both auth layers, revokes maintenance bypass, closes mobile nav); new `#restore-backup-modal-host` + `renderRestoreModal()`/`restoreBackupEntry()`/`closeRestoreModal()`/`setRestoreScope()`/`performRestoreModal()`; `renderBackupLog()` extended with per-row Restore buttons and restore-aware Details/trigger-label rendering; version bump to v6.12. |
| `security-helpers.php` | committed (`dc5d5f8`) | New `SAM_BACKUP_TABLES`/`SAM_AUCTION_SCOPED_TABLES` constants; new `sam_read_backup_file()`, `sam_backup_auction_ids()`, `restoreDatabaseBackup()` functions; `createDatabaseBackup()`'s inline table list replaced by `SAM_BACKUP_TABLES`. |
| `api.php` | committed (`dc5d5f8`) | New actions `get_backup_auctions`, `restore_backup` (added to `$allowedActions`/`$csrfProtectedActions`); new shared `sam_get_settings_password($pdo)` helper (also refactored into `verify_settings_password`). |
| `test.html` | committed (`dc5d5f8`) | 5 new suites covering all of the above. Confirmed green by the user. |

---

## What was accomplished this session (checkpoint v6.11 — committed, `b8468ab`; this write-up added retroactively)

**Note:** this checkpoint happened and was committed/deployed before this doc entry was written — the doc update step was skipped at the time. Reconstructed from the commit diff (`b8468ab`) rather than live conversation memory, so it's necessarily less detailed than a same-session write-up; treat the summary below as reliable but not exhaustive.

Server-side backups switched from raw `gzcompress()`-compressed bytes (a non-standard `.sql.gz`-named file that wasn't actually gzip format, just PHP's `gzcompress` output) to real `.zip` archives, using PHP's `ZipArchive` — a genuine `.zip` file that opens normally in any archive tool, containing one `.sql` entry with the JSON dump inside (same underlying format as before, just wrapped properly). `security-helpers.php`'s backup-writing path and `index.html`'s download-naming were both updated for the new `.zip` extension; `test.html` gained ~22 lines of new assertions, including one **confirmed by the user directly** (not just by code inspection) that a resulting backup file has a real `.zip` extension and opens as an actual archive. This is also why `sam_read_backup_file()` in the next section had to support `.zip` as a first-class format alongside the older `.sql.gz`/`.sql`.

### Files touched (v6.11, committed in `b8468ab`)
| File | Status | Notes |
|---|---|---|
| `security-helpers.php` | committed (`b8468ab`) | Backup writer switched from `gzcompress()` to `ZipArchive`-based real `.zip` output. |
| `api.php` | committed (`b8468ab`) | Minor changes accompanying the format switch (2 lines). |
| `index.html` | committed (`b8468ab`) | Download/display naming updated for `.zip`. |
| `test.html` | committed (`b8468ab`) | New assertions, including a user-confirmed real-file check. |

---

## What was accomplished this session (checkpoint v6.10)

Four related requests in sequence, all touching the Developer/Settings password gate and the Settings screen layout, plus a real bug found while verifying the last one.

### 1. Bug report — "both old and new [Developer] password fail"
The user reported the Developer password prompt rejecting both their previous password and the new one they'd just set via Settings → Change Password.

**Root cause, confirmed by reading the server's debug log via FTP** (`debug_log.txt`, downloaded to inspect actual `[API:verify_settings_password]` entries around the failure timestamps): `submitAuthPassword()` in `index.html` was comparing the typed password against `DB.getSettings().settingsPassword` — purely local, from whatever `sam_settings` happened to be in that browser's `localStorage`. Separately, `sam_guard_settings_passwords()` in `api.php` had a loophole: it only blocked a settings-blob write from reverting a password to the *known hardcoded default* (`'Gladiator#1'`) or blanking it, but still allowed any *other* non-empty value through — so a stale tab (one that hadn't synced since a password change on another device, or whose own change hadn't reached the server before a page-load re-sync fired) could silently roll the server's real password back to an older real value via the ordinary auto-sync `localStorage.setItem` → `action:'set'` hook that fires on every local write.

**Fix, in two parts:**
- **`api.php`:** new action `verify_settings_password` (added to `$allowedActions`, requires an authenticated session, rate-limited 8/5min same as `login`). Reads `sam_settings.settingsPassword` fresh from `sam_store` and compares with `hash_equals()`. Deliberately returns **403** on a wrong password, never 401 — `index.html`'s global fetch wrapper (`installApiAutoReauth`) silently re-logs-in and retries on any 401 from `api.php`, which would have doubled every failed verify attempt. If nothing is stored yet, falls back to the same `'Gladiator#1'` default `DEFAULT_SETTINGS` uses client-side, so a fresh install isn't locked out before ever setting a password.
- **`sam_guard_settings_passwords($incoming, $pdo)` in `api.php`:** rewritten to be strict. Previously: allowed a change if the incoming value was non-empty, different from stored, and not the hardcoded default. Now: **once a password field (`password` or `settingsPassword`) has any stored value, a `save_settings`/`set` blob write can never change it, period** — the stored value always wins. The only two paths now allowed to change an already-set password are the new `set_password` action and `reset_password` (see below). A first-ever value (nothing stored yet) can still be set via a blob write, so initial setup isn't broken.
- **`index.html` — `submitAuthPassword()`:** rewritten to `await` a call to `verify_settings_password` instead of comparing locally. Unlock button disables while the request is in flight; distinct error messages for wrong password (403), rate-limited (429), expired session (401), and network/other failures. A `document.getElementById('auth-modal').style.display === 'none'` guard after the await prevents a slow response from unlocking the menu after the user already clicked Cancel.
- **New shared helpers in `api.php`:** `sam_write_settings_password($pdo, $field, $newPassword)` (writes one password field, preserving every other settings key — same read-modify-write pattern `reset_password` already used) and `sam_validate_new_password($field, $pw1, $pw2 = null)` (≥6 chars, and if `$pw2` given, must match).
- **New action `set_password`:** the only authenticated path (besides reset) allowed to change a stored password. Takes `field` (`'password'` or `'settingsPassword'`) and `password`; CSRF-protected (added to `$csrfProtectedActions`).
- **`index.html` — `changeAppPassword()` / `changeSettingsPassword()`:** both rewritten around a new shared `saveServerPassword(field, newPassword)` helper that calls `set_password` and only updates the local copy (via the unwrapped `window._samOrigSetItem`, so it isn't re-posted as a blob write) after the server confirms. Both now report a real failure instead of `changeAppPassword()`'s old fire-and-forget "success" alert that could lie about whether the save actually took.

**Verified live** (not just by inspection): fetched the deployed `debug_log.txt`/`security.log` via FTP after deploying, confirmed `verify_settings_password` entries appear and fail/succeed as expected; confirmed a bogus `verify_settings_password` call against the live server returns 403 without a session, and with a session correctly compares against the real stored value.

### 2. Follow-up — "it works but I want to change it to my own password. Remove this default message"
Once server-side verification was live, the user hit a new (intentional) validation error: `sam_validate_new_password()` had briefly carried over the old "can't be the literal default `'Gladiator#1'`" rule from the pre-fix `sam_guard_settings_passwords()`. The user pointed out they specifically wanted to set it back to a value like the default. **Removed that restriction entirely** — since the strict guard above now makes the underlying corruption scenario (a stale blob silently reverting to the default) structurally impossible, the "not the default" rule no longer served its original purpose and only got in the way of a deliberate admin choice. `sam_validate_new_password()` now only checks length (≥6) and, when both are supplied, that the two entries match.

### 3. New feature — "add a password reset option to the developer password modal"
Added a **"Forgot password?"** link directly inside `#auth-modal` (the Developer password prompt), separate from the login screen's existing one.
- **`api.php` — `forgot_password`/`reset_password` both gained a `scope` parameter** (`'login'` default, or `'settings'`). Each scope uses its own token row in `sam_store` (`sam_password_reset` vs. `sam_settings_password_reset`) so a pending reset link for one can't be replayed against the other, and requesting one scope's reset doesn't invalidate a pending link for the other. `scope:'settings'` writes `settingsPassword` instead of `password` on completion, and the emailed subject/body/reset-page text say "Developer (Settings) password" instead of the generic wording.
- **`get_all`'s query changed** to `SELECT ... WHERE key NOT IN ('sam_password_reset', 'sam_settings_password_reset')` — these token rows are server-only bookkeeping; shipping them to every logged-in browser would let anyone with just the login password read/consume a pending Developer-password reset token without ever touching the admin inbox it was emailed to.
- **`index.html`:** new `requestSettingsPasswordReset()` function and a "Forgot password?" link + status line added directly under the password field inside `#auth-modal`. Same UX pattern as the existing login-screen link (success/too-many-attempts/generic-failure messaging), posts with `scope:'settings'`.
- **`reset-password.html`:** reads `?scope=settings` from the URL alongside the existing `?token=`, and when present relabels the page title/heading/field label/success message to say "Developer Password" instead of the generic "Password", and passes `scope` through in the `reset_password` POST body. An invalid/expired link's error message now correctly points back at "the Developer password prompt" instead of always saying "the login screen."

**Verified live:** confirmed the deployed `index.html` contains `requestSettingsPasswordReset` and the deployed `reset-password.html` passes `scope` through; confirmed a bogus token against `reset_password` with `scope:'settings'` returns the expected "invalid or expired" error rather than silently succeeding or 500ing.

### 4. New feature — "move import members, backups into settings with collapsable cards"
Reorganization request, no behavior change to the underlying features themselves.
- **Removed** the `#nav-developer-submenu` rows for `data-screen="import-members"` and `data-screen="backups"`, and their two full-screen `<section>`s (`#screen-import-members`, `#screen-backups`) entirely from `index.html`.
- **Added two new `.card`s inside `#screen-settings`**, positioned right after the existing "Developer Tools" card and before "Gmail OAuth": an **Import Members** card (CSV upload, Import History table, member list table, Delete All) and a **Backups** card (Backup Now button, View Logs toggle, automatic-backup schedule fields) — same markup structure (`.card` / `.card-header` / padded body) as every other Settings card, so `initCollapsibleCards('screen-settings')` (already generic across all direct-child `.card`s in that section) picks them up automatically with no new collapsible-specific code needed.
- **New `data-settings-autosave="off"` convention:** both new cards are marked with this attribute, and `initSettingsAutoSave()`'s delegated `change` listener now checks `e.target.closest('[data-settings-autosave="off"]')` and skips calling `saveSettings()` for anything inside — necessary because Import Members' file input and Backups' schedule checkboxes/dates fire native `change` events but have their own dedicated save functions (`importMembersCsv()`, `saveBackupScheduleFields()`) that have nothing to do with the general settings blob.
- **`navigate()`:** removed `'import-members'` and `'backups'` from `screenNames`, `developerScreens`, and the screen-specific post-navigation dispatch (the two `if (screenId === ...)` lines that used to call `refreshImportMembersTable()`/`loadBackupSchedule()` on entering those screens directly).
- **`loadSettingsForm()`:** now calls `refreshImportMembersTable()`, `refreshImportHistoryTable()`, and `loadBackupSchedule()` directly, since those data loads used to happen only when navigating into the old dedicated screens and now need to happen every time Settings itself loads.
- Updated the in-app User Manual's "Data & Sync" paragraph and one `debugLog()` message that referenced "Developer > Backups" / "Developer > Import Members" as prose, to instead say "the Backups card in Settings" / "Settings > Import Members".
- **Also removed, per an explicit user instruction mid-session ("remove comments about default passwords"):** the `(default: ETCCauctionoct2026)` and `(default: Gladiator#1)` hints under the Login Password and Settings Password fields in the Settings → Security card. These literal default values being visible in the UI (and in page source, since they're static HTML) was flagged as a minor exposure in its own right — removing the on-screen hint doesn't change the underlying `DEFAULT_SETTINGS` values in `index.html`'s JS, which are still there (see open item #16 above for the more significant related exposure that's still unaddressed).

**Verified live:** fetched the deployed `index.html`, confirmed the two nav-subitems and both old `<section>` ids are absent, confirmed both new `.card-header`s ("Import Members", "Backups") are present, confirmed the default-password hint strings are absent.

### 5. Real bug — "developer tools section should be collapsable" (found while resuming this session)
A follow-up session (`/ETCCSAMBegin` → `/ETCCSAMCheckpoint`) picked this work back up and, orienting on the reorg above, the user reported the "Developer Tools" card wasn't actually collapsing when clicked.

**Root cause:** `initCollapsibleCards()` (the generic mechanism already covering every direct-child `.card` in `#screen-settings`, added as part of item #4 above) was working correctly — clicking a card header does toggle its `.collapsed` class. The bug was in the CSS meant to hide the content: `.card.collapsible.collapsed > :not(.card-header) { display: none; }` is a class selector, and **an inline `style="display:..."` attribute always beats a class selector, however many classes are chained, regardless of source order.** The "Developer Tools" card's content wrapper carries `style="padding:16px;display:flex;flex-direction:column;gap:14px;"` — that inline `display:flex` silently defeated the collapse rule every time, so the card visually never hid its content even though the class was toggling correctly under the hood. Every *other* Settings card happened to only set non-conflicting inline properties (padding, etc.) on its content wrapper, so they collapsed fine — this was specifically a Developer Tools (flex-laid-out body) problem, but structurally could recur on any future card that lays out its body with an inline `display`.

**Fix:** added `!important` to the collapse rule (`index.html`, the `<style>` block near the top): `.card.collapsible.collapsed > :not(.card-header) { display: none !important; }`. This makes the collapse win over any card's own inline display style going forward, not just Developer Tools' current one.

**Verified live:** deployed and confirmed via `Last-Modified` header that the CSS change landed.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (checkpoint v6.10) | `submitAuthPassword()`/`authenticateSettings()`/`closeAuthModal()` rewritten around server verification; new `requestSettingsPasswordReset()`, `saveServerPassword()`; `changeAppPassword()`/`changeSettingsPassword()` rewritten; Import Members/Backups `<section>`s removed, two new `.card`s added inside `#screen-settings`; `loadSettingsForm()` now loads their data directly; `initSettingsAutoSave()` gained the `data-settings-autosave="off"` skip; default-password hints removed from Security card; collapse-rule CSS gained `!important`; version bump to v6.10 |
| `api.php` | committed (checkpoint v6.10) | New actions `verify_settings_password`, `set_password`; `forgot_password`/`reset_password` gained `scope`; new helpers `sam_write_settings_password()`, `sam_validate_new_password()`; `sam_guard_settings_passwords()` rewritten strict; `get_all` excludes both reset-token keys |
| `reset-password.html` | committed (checkpoint v6.10) | Reads `?scope=settings`, relabels page text and passes `scope` through to `reset_password` |
| `security-helpers.php` | committed (checkpoint v6.10) | Supporting changes for the above (rate-limit config / CSRF list entries) |
| `test.html` | committed (checkpoint v6.10) | Stale suites rewritten (Import Members/Backups card location, `submitAuthPassword()` behavior, `sam_guard_settings_passwords()`); 6 new suites added covering the strict guard, server-side verification, the password-change rewrite, the scoped reset flow, and the collapsible-card `!important` fix. Confirmed green by the user. |

### Files touched this session (uncommitted)
| File | Status | Notes |
|---|---|---|
| `api.php` | **modified, deployed, not committed** | New actions: `verify_settings_password`, `set_password`; `forgot_password`/`reset_password` gained `scope` param with separate token rows; `sam_guard_settings_passwords()` rewritten strict (blob writes can never change an already-set password); new helpers `sam_write_settings_password()`/`sam_validate_new_password()`; `get_all` excludes both password-reset token rows; `verify_settings_password`/`set_password` added to `$allowedActions`, `set_password` added to `$csrfProtectedActions`, rate limit added for `verify_settings_password`. |
| `security-helpers.php` | **modified, deployed, not committed** | Added `getRateLimitConfig()` entry for `verify_settings_password` (8/5min). (Note: this file's earlier-in-session backup-feature diff — `samBackupPurge`/`samReadBackupHistory`/etc. — predates this write-up and was already present/uncommitted going into this session; not newly added here.) |
| `index.html` | **modified, deployed, not committed** | `submitAuthPassword()` rewritten to verify server-side; new `saveServerPassword()`/`requestSettingsPasswordReset()` helpers; `changeAppPassword()`/`changeSettingsPassword()` rewritten around `saveServerPassword()`; `#auth-modal` gained a Forgot-password link/status line; Import Members and Backups moved from standalone Developer screens into two new collapsible `.card`s in `#screen-settings`; `navigate()`/`screenNames`/`developerScreens` updated accordingly; new `data-settings-autosave="off"` opt-out wired into `initSettingsAutoSave()`; `loadSettingsForm()` now loads Import Members/Backups data directly; removed the two default-password UI hints; User Manual text and one debugLog message updated to match the new locations. |
| `reset-password.html` | **modified, deployed, not committed** | Reads `?scope=settings` from the URL; relabels title/heading/field/messages for the Developer-password case; passes `scope` through in the `reset_password` POST body. |

---

## What was accomplished this session (checkpoint v6.9)

Two threads, both starting from a plain question the user asked while reviewing the app: "is the members table used by the website other than the import."

### 1. Investigation — members table usage, and a real orphaned-code find
Grepped every call site of `DB.getMembers()`/`sam_members` across `index.html`, `api.php`, and `add-item.php`. Found four consumers, reported back to the user, and one of them turned out to be dead:
- **`add-item.php`** — genuinely active, reads `sam_members` live for its "ETCC Member Name" dropdown.
- **`loadSettingsForm()`** and **`refreshImportMembersTable()`** — read-only status/list display, not really "using" the data functionally.
- **The in-app "Member Database" modal** (`showMemberDBModal()` and friends) — code fully built out (search box, roster table, "Add to Bidders" button), but grepping every `onclick` in the file turned up **no button anywhere that ever called `showMemberDBModal()`**. It was unreachable from the UI. Interestingly, `add-item.php`'s own code comment already called this screen "(now-orphaned)" — a past session apparently knew, but the code itself was never cleaned up or logged in this doc.

### 2. Removed — the dead Member Database modal
Per explicit user request ("remove dead modal code") once the finding above was confirmed. Deleted outright (not flagged-and-kept, since it was genuinely unreachable, unlike the in-row-edit cluster from v6.6 which stayed flagged):
- CSS: `#member-db-modal`/`#member-db-modal-table` rules.
- HTML: the whole modal block (search input, roster table, Add to Bidders / Clear / Close buttons).
- JS: `closeMemberDBModal()`, `showMemberDBModal()`, `selectAllMembers()`, `filterMemberTable()`, `addCheckedToWalkins()`.
- A dangling refresh-check left in the "Clear Members" Developer Tools button handler (`if (typeof showMemberDBModal === 'function' && ...) showMemberDBModal();`) — harmless once the modal was gone (the `typeof` guard made it a no-op), but removed rather than left as dead code referencing dead code.

Left alone: the four legitimate consumers listed above, and a **second, separately-already-orphaned** `importMemberCSV()` function (differently cased from the live `importMembersCsv()`) targeting a `#inp-member-csv` field that doesn't exist in any visible screen — it already carried its own "removed from the UI in v4.0 but left" comment and wasn't touched.

### 3. New feature — member Import History log
Follow-up request after seeing the Import Members screen (372 imported members, a destructive "Importing replaces the entire member list" warning already in the UI copy, no record of past imports). Added:
- `DB.getMemberImportHistory()` / `DB.addMemberImportHistoryEntry(count)` — a new `sam_members_import_history` localStorage key, read/written with plain `getItem`/`setItem` calls. No new API action needed: any `sam_`-prefixed key already auto-syncs to the server via the existing global `localStorage.setItem` wrapper's `action:'set'` call.
- `importMembersCsv()` (the live, UI-wired import function) now calls `addMemberImportHistoryEntry(members.length)` right after `DB.saveMembers(members)`.
- A new **"Import History"** card on the Import Members screen, between the CSV upload form and the current member-list card — Date/Time and Members Imported columns, newest first (`unshift()`, not `push()`).
- **Deliberately separate from `sam_members` itself** — `clearImportedMembers()` ("Delete All") never touches the history key, so the log survives a member-list clear. That's the entire point of "keep history": it's a persistent log of import *events*, not a second copy of the current roster.
- **Known limitation, not addressed this session:** no cap and no manual "Clear History" control — the log grows unbounded. Flagged to the user directly rather than silently designed around; acceptable for now since imports happen only a handful of times per auction cycle.

### 4. `test.html` updated, confirmed green
Two new suites: `'Registrations — dead Member Database modal removed (this session)'` (3 assertions covering the full removal, the dangling-handler cleanup, and that legitimate consumers were untouched) and `'Import Members — Import History log (this session)'` (7 assertions, including executable checks — via a local mirror of the prepend logic — that history entries accumulate rather than overwrite, and that new entries land newest-first).

### 5. Checkpoint v6.9 (minor version bump)
Run via `/ETCCSAMAll`, which chains `/ETCCSAMCheckpoint` then `/ETCCSAMEnd`. Mid-checkpoint, the user asked "change log is not updated" — this was expected, not a bug: the in-app Change Log screen (`loadChangelog()`) pulls commit history live from GitHub, so it naturally showed nothing until this session's work was actually committed and pushed. Minor bump from v6.8 via `.\bump-version.ps1` (no `-Major` requested).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`9a1deb5`) | Member Database modal fully removed (CSS/HTML/5 JS functions + dangling handler reference); new Import History log (`DB.getMemberImportHistory()`/`addMemberImportHistoryEntry()`, wired into `importMembersCsv()`, new UI card + `refreshImportHistoryTable()`); version bump to v6.9 |
| `test.html` | committed (`9a1deb5`) | 2 new suites / 10 assertions, including executable prepend-logic checks. Confirmed green by the user. |

---

## What was accomplished this session (checkpoints v6.7, v6.8)

New day, new topic from the v6.6 session above — Print Bid Sheets' output layout, driven entirely by real print screenshots the user sent along the way, plus an unrelated but important deploy-tooling fix discovered mid-session.

### 1. New feature — Print Bid Sheets goes from 2 pages/item to 4-page duplex layout
**Request:** "Print 2 double sided pages. Page 1 side 1 as it currently is. Page 1 side 2 fill with 20+ rows. Page 2 side 1: the current page 2. Page 2 side 2: blank page."

Implemented in `printBiddingSheets()` (`index.html`): each item's HTML block now emits 4 page `<div>`s in order — `.sheet` (unchanged page-1-front bid table), a new `.sheet.sheet-continuation` (page-1-back — bid table only, no description/info boxes, header reads "Item # X (continued)"), `.label-page` (unchanged page-2-front description/donor page), and a new empty `.blank-page` (page-2-back). With a printer set to duplex, sides pair up correctly. The bid-amount ladder (Premium items) was made continuous across both bid tables: `bidAmounts` now generates `bidCount + continuationRowCount` values in one loop (so `prevAmt`/ascending-dedup logic runs unbroken), then splits into `bidRows`/`bidRows2` by slicing. Open-bid items correctly leave every continuation row blank too (`mkBidRow(amt, idx)` blanks any row where `isOpenBid && idx > 0`, and continuation rows pass `idx = i + bidCount` so they're always `> 0`).

Initial continuation row count was `26` (chosen when row height was `0.28in`, see below) — reduced to `23` after row height reverted to `0.40in`, once a real print screenshot showed the continuation table overflowing onto page 2's front at the taller row height.

### 2. Real bug — "Bid Amount" header silently wrapping to two lines, plus row-height back-and-forth
**Symptom (from a screenshot):** only ~16-17 of the intended 20 rows fit on page 1 before overflowing onto the next physical page.

**What happened, in order (see open item #13 above for the full narrative):**
1. Assumed the fixed `BID_SHEET_ROW_HEIGHT_IN` (was `0.40`) was simply too tall for the page budget — shrunk it to `0.35`, still overflowed per a second screenshot (17 of 20 rows), shrunk further to `0.28`, plus `.sheet` padding `0.12in→0.05in` to reclaim more space. This "worked" (all 20 rows fit) but left a lot of unused blank space at the bottom of the page.
2. Later, the user reported their **print-dialog scale setting had been wrong** and asked to "fix layout" now that scaling was corrected. Re-examining the screenshots at that point pointed at the true root cause: the `.bid-table th` "Bid Amount" header was wrapping to two lines ("Bid"/"Amount") in a narrow column, silently taking more vertical space than assumed and eating into the 20-row budget — not that `0.40in` rows were inherently too tall.
3. Fixed properly with `white-space: nowrap` on `.bid-table th`, then **reverted** `BID_SHEET_ROW_HEIGHT_IN` back to `0.40` and `.sheet` padding back to `0.12in 0.25in` — the shrink was a workaround for a bug that's now actually fixed, and page 1 now fills properly again at the original page-filling size.
4. That revert then broke the continuation page (still using `continuationRowCount = 26`, sized for the smaller `0.28in` rows) — a screenshot showed it overflowing onto page 2's front. Reduced to `23`, confirmed via the user's next screenshot check.

**Takeaway for future sessions:** row height and continuation-row-count are coupled constants in the same function with no automatic relationship between them — changing one without recalculating the other will overflow a page. Also: always ask/verify the user's print-dialog **Scale** and **Margins** settings match between destinations (PDF vs. physical printer) before diagnosing a layout bug as a CSS issue — a wrong Scale setting on the user's end can look identical to a real overflow bug in a screenshot.

### 3. Deploy tooling — `deploy.ps1` switched from `curl.exe` to `.NET FtpWebRequest`
**Discovered mid-checkpoint:** `deploy.ps1`'s curl-based upload started failing on *every* attempt (not intermittently) — `curl: (56) response reading failed`. Investigated with `curl -v`: the file transferred **100% of its bytes** every time, but curl couldn't read the server's final "226 Transfer complete" response over the control channel (`schannel: server close notification received` right after the data upload finished) and reported exit 56 regardless. Confirmed via `Last-Modified` header checks that **repeated identical-looking "failures" had genuinely not updated the live file** — a real correctness risk, since `deploy.ps1` was reporting "FAILED" honestly, but a less careful read of that output (or a differently-behaved retry) could easily be mistaken for "probably fine, curl is just flaky."

**Root cause:** `curl.exe` on this machine uses the Windows Schannel TLS backend, which has a bug/incompatibility with this Hostinger FTPS server's connection-closing behavior after a completed transfer.

**Fix:** rewrote `Deploy-File` in `deploy.ps1` to use `System.Net.FtpWebRequest` directly instead of shelling out to curl — reads the local file into memory, opens the FTP request stream, writes the bytes, and checks the server's actual response object rather than an external process's exit code. Added `New-RemoteDir` to recreate curl's old `--ftp-create-dirs` auto-directory-creation (walks each path segment, calling `MakeDirectory`, ignoring "already exists" errors). Added a 300ms `Start-Sleep` between files in full-deploy mode after hitting a real (but different, and transient) `450 File unavailable (file busy)` error under rapid back-to-back connections — confirmed that error resolves on individual retry, unlike the curl issue which did not. Verified end-to-end: single-file deploys, a full all-files deploy (including nested `backend/routes/*.js` — exercises `New-RemoteDir`), and `Images/ETCC-Logo.ico`/`js/toolbar.js` specifically (the two files that hit the transient busy error). Every "OK" now cross-checked against a fresh `Last-Modified` header to confirm it's not a repeat of the curl false-positive/false-negative problem. Committed separately from the checkpoints (`3f2bbee`, "Switch deploy.ps1 from curl to .NET FtpWebRequest") since the user asked for it as a standalone "commit deploy.ps1", not as part of a checkpoint.

### 4. `test.html` updated, confirmed green (both checkpoints)
**v6.7:** new suites `'Bid Sheet — duplex 4-page layout (v6.7)'` (8 assertions covering the 4-page structure, continuation page content, and the continuous bid ladder) and `'Bid Sheet — page 1 row height fits all 20 rows (v6.7)'` (3 assertions, later annotated as superseded once v6.8 reverted the values). The pre-existing `'Bid Sheet — exactly two pages per item'` suite was renamed to `'Bid Sheet — description & label-page layout'` and its page-count-specific assertions removed, since the app no longer emits exactly two pages per item.

**v6.8:** new suite `'Bid Sheet — row height reverted to page-filling value (v6.8)'` (3 assertions covering the `0.40in`/`0.12in`/`continuationRowCount=23` revert), plus the v6.7 row-height suite and the duplex-layout suite's stale `26`/`0.28in`/`46`-specific assertion text updated to reflect the final v6.8 numbers and point at v6.8 for the current state.

### 5. Checkpoints v6.7 and v6.8 (minor version bumps)
Two straightforward minor bumps (`.\bump-version.ps1`, no `-Major` requested) — v6.7 after the duplex-layout feature landed and was confirmed working via screenshot, v6.8 after the row-height revert/continuation-count fix was confirmed via a follow-up screenshot.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`7f6ddea` for v6.7, `57e0e30` for v6.8) | `printBiddingSheets()`: 4-page duplex template, continuous bid ladder across pages, row-height/padding/continuation-count constants revised twice; `.bid-table th { white-space: nowrap }` fix; `.blank-page`/`.sheet-continuation` CSS added; version bumps to v6.7 then v6.8 |
| `test.html` | committed (`7f6ddea`, `57e0e30`) | v6.7: 2 new suites/11 assertions, 1 suite renamed+trimmed. v6.8: 1 new suite/3 assertions, 2 prior suites' text updated to point at final values. Confirmed green by the user both times. |
| `deploy.ps1` | committed separately (`3f2bbee`, between v6.7 and v6.8) | `Deploy-File` rewritten to use `System.Net.FtpWebRequest`; new `New-RemoteDir` helper; 300ms inter-file delay in full-deploy mode; netrc-file setup/cleanup removed as dead code |

---

## What was accomplished this session (checkpoint v6.6)

Two-part follow-up in the same day as v6.5 below — a new interaction pattern requested by the user (double-click to edit), then a cleanup request that removed the UI it replaced.

### 1. New feature — double-click a Donated Items row opens an Edit Item modal
**First request:** "double clicking a row should open editing" — implemented initially as attaching a double-click handler that called the existing in-row `editItemByNumber()` (same as the Edit button, just triggered by a dblclick instead of a click).

**Follow-up request, same turn:** "open editing form in a modular page with save and cancel" — this meant a proper modal, not in-row inline editing. Built `#item-edit-modal` (`index.html`, inserted at the end of the Step 1 `screen-item-load` section, right before its closing `</section>`) modeled directly on the existing `#bidder-edit-modal` pattern:
- Fields: Item #, Category (`<select>` built from the global `CATEGORIES` map), Submission Date, Description (`<textarea>`), Item Value, Reserve Amount, Donor Name (`<textarea>`), Donor Email (`<textarea>`), Donor Phone.
- `openItemEditModal(itemNumber)` populates every field and stores the original item number on `modal.dataset.originalItemNumber` (so a rename mid-edit doesn't break the save lookup).
- `saveItemEditModal()` writes all fields back, sets `source = 'Edited'`, and — **a real improvement over the old in-row editor, which never had this** — if the Item # was changed and a winner record exists under the old number, it's copied to the new key and the old key deleted, so renaming an item no longer silently orphans a recorded win.
- `closeItemEditModal()` is a plain hide, no side effects (Cancel behavior).
- The double-click listener (attached per-row in `refreshItemsTable()`) skips read-only mode, and bails via `e.target.closest('input, button, select, textarea, a')` so clicking the row's checkbox or (at the time) the Edit/View buttons didn't also fire the modal.

**Follow-up sizing request:** a screenshot of the modal in use showed the Description box looking cramped relative to real donation descriptions — bumped `#edit-item-description` from `rows="3"` to `rows="8"` to match.

### 2. Removed — the Actions column (View/Edit buttons) from Donated Items
Once double-click became the primary edit path, the user asked to remove the Actions column and its buttons entirely. Removed:
- `<th>Actions</th>`, its `<col style="width:200px;">`, and the row template's trailing `<td>` (View + Edit buttons) from `#items-table`.
- Table `min-width` recalculated 1986px → 1786px (verified against the sum of the 11 remaining column widths); empty-state colspan 12 → 11.
- `emailIdx`/`EMAIL_ICON`, which existed solely to feed the removed View button — deleted outright as genuinely dead loop-local code, not flagged (there's nothing standalone worth preserving there, unlike a whole function).

**Orphaned, not deleted** (per the project's standing convention): `ITEM_EDIT_COLS_MAIN`, `rowCheckboxOffset()`, `editItemByNumber()`, `saveItemEdit()`, `cancelItemEdit()` — the whole in-row-edit cluster lost its only caller. Left in place with an explicit `ORPHANED` comment block above `ITEM_EDIT_COLS_MAIN` naming the cluster, explaining why, pointing at the replacement, and flagging that `ITEM_EDIT_COLS_MAIN`'s `actions:10` index is now stale (the column it pointed at no longer exists) in case this code is ever revived without updating it first.

**Checked, needed no change:** `showEmailModal()` itself is still called from the separate Email inbox table's own View button — only the Donated Items call site was removed. The View All modal clones `#items-table`'s thead/tbody dynamically with no hardcoded column-count assumption, so it picked up the Actions-column removal automatically.

### 3. `test.html` updated, confirmed green
Three new suites: the modal itself (8 assertions — population, field types, save/rename-carries-winner/empty-Item#-rejected, cancel), the Actions-column removal (5 assertions), and the orphaned-code flagging (3 assertions).

### 4. Checkpoint v6.6 (minor version bump)
Straightforward minor bump from v6.5 via `.\bump-version.ps1` (no `-Major` requested).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`b3b80a7`) | `#item-edit-modal` + `openItemEditModal()`/`saveItemEditModal()`/`closeItemEditModal()`; double-click listener in `refreshItemsTable()`; Actions column removed (header/colgroup/row/colspan/min-width); `emailIdx`/`EMAIL_ICON` deleted; in-row-edit cluster flagged orphaned; version bump to v6.6 |
| `test.html` | committed (`b3b80a7`) | 3 new suites / 16 assertions. Confirmed green by the user. |

---

## What was accomplished this session (checkpoint v6.5)

Small, single-topic follow-up in the same day as v6.1→v6.4 below — the user pointed out a real overflow issue on a report screen not touched earlier in this session.

### 1. Real bug — Items Not Won report's Item # and Donor Phone columns wrapped onto two lines
**Symptom (from a screenshot):** on the standalone "Items Not Won" print report (`printItemsNotWonReport()` in `index.html`, ~line 8750), the "Item #" header wrapped to "Item" / "#" and "Donor Phone" wrapped to "Donor" / "Phone" — the narrow columns didn't have room for the two-word labels on one line, and phone number values in the data rows could wrap too.

**Fix:** added `style="white-space:nowrap;"` to both the `<th>` header cells and the corresponding `<td>` data cells for Item # and Donor Phone. Left Description, Category, Donor Name, and Donor Email untouched — those are meant to wrap.

### 2. `test.html` updated, confirmed green
New suite `'Items Not Won report — Item # / Donor Phone no longer wrap (v6.5)'` (3 assertions covering the header fix, the data-cell fix, and that unrelated columns were left alone). Deployed via `.\deploy.ps1 test.html`, confirmed green by the user before the checkpoint.

### 3. Checkpoint v6.5 (minor version bump)
Straightforward minor bump from v6.4 via `.\bump-version.ps1` (no `-Major` requested).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`7636b2c`) | `white-space:nowrap` added to Item # / Donor Phone header + data cells in the Items Not Won report; version bump to v6.5 |
| `test.html` | committed (`7636b2c`) | 1 new suite / 3 assertions. Confirmed green by the user. |

---

## What was accomplished this session (checkpoint v6.4)

Small follow-up found by the user reviewing live screens right after the v6.3 checkpoint — the same donor-name overflow bug fixed on the Donated Items table earlier in this session had a twin on a different table, and a page-height inconsistency on Registrations.

### 1. Real bug — Donor Name/Email overflow recurred on the Create Bid Sheets table
**Symptom:** the same visual overflow already fixed earlier this session on `#items-table` (Donated Items) reappeared on `#bs-items-table` (Create Bid Sheets) for the same item (900-1, "Wilderness Trail Distillery, Attn. Grayson Yaden").

**Root cause:** the two tables have **entirely separate row-rendering functions** (`refreshItemsTable()` vs. `refreshBsItemsTable()`) — fixing one's Donor Name/Email `<td>` styling doesn't touch the other's.

**Fix:** added the same `white-space:normal;word-break:break-word;vertical-align:top` to `refreshBsItemsTable()`'s donorName/donorEmail cells (`index.html:6053-6054`). Also checked `printDonatedItemsList()` (the standalone "🖨 Print" page) — it uses default (non-`table-layout:fixed`) table layout, so it isn't exposed to this bug class at all and needed no change.

### 2. Real bug — Registrations table subtracting ~110px more than sibling screens
**Symptom:** the Registrations table (32 bidders) showed noticeably fewer rows before scrolling than comparable screens, with unexplained empty space below the card.

**Root cause:** `#bidders-card`'s scroll container used `max-height:calc(100vh - 388px)`, while every structurally similar screen (Winning Bidders, Create Bid Sheets, Payments) uses `calc(100vh - 278px)` — despite those screens having comparable extra content above their tables (e.g. Winning Bidders' save-note bar vs. Registrations' metric-row). No comment explained the 388px figure; it was a silent outlier.

**Fix:** matched the established `278px` convention rather than guessing a new number (`index.html:1255`).

### 3. `test.html` updated, confirmed green
New suite `'Create Bid Sheets table — Donor Name/Email wrap fix (v6.4)'` (2 assertions) and `'Registrations table — scroll height matched to sibling screens (v6.4)'` (2 assertions).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`35ae34f`) | `#bs-items-table` donor wrap fix; `#bidders-card` scroll height 388px→278px; version bump to v6.4 |
| `test.html` | committed (`35ae34f`) | 2 new suites / 4 assertions. Confirmed green by the user. |

---

## What was accomplished this session (checkpoint v6.3)

The largest single checkpoint of this multi-day arc — the bid sheet algorithm was restated from scratch by the user, went through a mid-session revision that turned out wrong, got corrected by a real bug report, and the printed sheet's layout was overhauled to a fully fixed-height design. Three test suites had gone stale or actively wrong by the end and were rewritten, not just added to.

### 1. Bid sheet algorithm restated as OPEN vs. PREMIUM auctions
The user restated the entire algorithm explicitly (quoted here since it's the rule now in force):

> First a bid sheet is either for an **open auction** (Value ≤ $35.00) or a **premium auction** (Value > $35.00). Open auction: only the first bid is preprinted — $1 with no reserve, or the reserve when present. Premium auction: all bids preprinted — first bid is the reserve when present or 30% of value when no reserve; increment is 10% of value if value < $100, 7% if value ≥ $100.

Implemented in `printBiddingSheets()` (`index.html`) with `isOpenBid`/`startingBid`/`incPct` variables named to match this terminology directly, replacing several sessions' worth of accumulated ad-hoc comments. **Caught and fixed a real boundary bug while rewriting**: the old code used `Value > $100 → 7%`; the user's restatement is `Value ≥ $100 → 7%`. An item valued at exactly $100 was getting the wrong bracket before this fix.

### 2. Mid-session revision, then a reversal (important — read if touching this code)
Before landing on the above, this session went through **two prior revisions of "what counts as OPEN"** within the same conversation:
1. **v6.0** (previous checkpoint): OPEN required *no reserve at all* — any reserve excluded an item outright, regardless of value.
2. **A revision partway through this session**: a reserve counted toward the OPEN check too — an item was OPEN if `(reserve if set, else value) <= threshold`. This shipped as part of the v6.2 checkpoint.
3. **This session's correction**: a live bug report — item 800-1, Value $50 (above the $35 threshold) but Reserve $25 (below it) — printed a **blank** OPEN-style sheet under rule #2, when the user expected a normal, fully preprinted sheet since the item's actual value was well above the threshold. The user confirmed explicitly: **Value alone decides OPEN vs. PREMIUM membership**; reserve only ever affects the *first bid amount*, never membership. Rule #2 was reverted.

`test.html` now carries a suite specifically titled to document this reversal (`'Bid Sheets — reserve does NOT decide OPEN/PREMIUM membership (v6.2, corrected)'`) so a future session reading old commits or a stale summary doesn't resurrect rule #2.

### 3. `starting-bid-list.php` updated to match, each time
Both the reserve-counts revision and its reversal were mirrored into `starting-bid-list.php` in the same sessions they happened in JS, keeping the two files in sync throughout (per the standing convention documented in open item #6 above).

### 4. Bid sheet layout — fixed-height Description and Category/Donor/Value/Reserve boxes
Requested from a screenshot of a real printed sheet showing a 4-line Description box and a 2-line Category/Donor/Value row — the user wanted every sheet to use that same fixed layout regardless of content length.

- **Description box**: `height:0.92in` (computed from the box's 16.8px font / 1.2 line-height + padding/border), `overflow:hidden`, `box-sizing:border-box`. Hard `height`, not `min-height` — the user explicitly asked for fixed values, not a content-driven minimum.
- **Category/Donor/Value/Reserve row**: `height:0.50in` on the CSS grid container, plus explicit `line-height:1.2` on each cell (not previously set).
- **Real bug found and fixed mid-implementation**: setting `height:0.50in` on the grid container alone wasn't enough — its implicit `auto`-sized row track still grew to fit the tallest cell's natural content, and a 3-line donor address ("Wilderness Trail Distillery, Attn. Grayson Yaden") bled into the bid table's header row below it, even though each cell already had its own `overflow:hidden`. Fixed by adding `grid-auto-rows:0.50in` **and** `overflow:hidden` **on the container itself** — a child's own overflow:hidden doesn't stop its grid container from growing.
- Simplified `page-break-inside` to always `'avoid'` (previously switched to `'auto'` past 400 description characters to avoid a Chrome print-layout hang) — now that every box is fixed-height, the sheet's total height can never grow, so that risk no longer exists.
- **Row height raised 0.28in → 0.40in** ("to better fill the page" — this became the v6.4-adjacent `BID_SHEET_ROW_HEIGHT_IN` constant, see below).

### 5. Removed: per-item Row Height column + toolbar control (Create Bid Sheets table)
Per explicit user request ("remove row height from each row and header", then "eliminate the toolbar's single global Row Height (in) control" as a follow-up in the same turn):
- Removed `<th>Row Height</th>`, its `<col>`, the per-row `<input class="bs-row-height">`, and `updateItemRowHeight()` (no remaining callers).
- Removed the entire toolbar `rowHeightWrap`/`rowHeightInput` construction and its change handler, including the per-item cascade-cleanup logic that only existed to service the now-gone override.
- Replaced with a single fixed JS constant, `BID_SHEET_ROW_HEIGHT_IN` (0.28, later changed to 0.40 per item 4 above), read nowhere from Settings.
- `DEFAULT_SETTINGS.bidRowHeight` left in place, flagged as orphaned in a comment (existing stored settings/items may still carry the old value) — nothing reads or writes it anymore.

### 6. Removed: Date Loaded column (Create Bid Sheets table)
Straightforward column removal (header, `<col>`, row cell, empty-state colspan). The underlying `item.loaded_date` data field is untouched — still set on item creation elsewhere, just no longer displayed in this table.

### 7. `test.html` — three suites rewritten (not just added to), because they had gone stale or actively wrong
- `'Bid Sheet — Bid Amount Algorithm'` (v3.1) — was still asserting the pre-Open-Bids formula (increment based on reserve-substituted value, not Item Value). Updated the local `computeBidAmounts()` mirror and its expected numbers (one assertion's expected diff changed from 8 to 7).
- `'Bid Sheet — Row Height (Global + Per-Item)'` (v3.1) — described a feature that no longer exists. Rewritten to explicitly document the removal rather than deleted outright, so a future session sees "this was removed" instead of just finding no test at all.
- The three "Open Bids" suites from v6.0/v6.2 — one still used the old `reserveRaw === 0` check, one **actively asserted the now-reversed rule** ("a reserve counts toward Open Bids"), and the Starting Bid List suite quoted stale PHP. All rewritten; the reversed one was kept as its own dedicated suite specifically to document the reversal (see item 2 above).
- Every executable numeric assertion (`bidLadder()`, `computeBidAmounts()`) was independently re-verified against the real formulas in a standalone Node script before deploying, not just eyeballed — this caught the false "Open Bids = 0 disables the category entirely" claim from the v6.0 session (a $0/undeclared item still qualifies at exactly 0) during a prior pass, and this session's rewrite followed the same discipline.

### 8. Checkpoint v6.3 — version bump correction
`/ETCCSAMCheckpoint` (no version argument) was run and a **major** bump to v7.0 was applied by mistake — the standing procedure is minor-by-default, major only if the user explicitly asks, and no such request was made this time. Caught before committing; reverted to **v6.3** (correct minor bump from v6.2) via a direct edit to the footer span, then re-deployed and committed normally. No v7.0 was ever pushed or is live anywhere.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`94fd61d`) | OPEN/PREMIUM algorithm rewrite + `>=100` bracket fix; fixed-height Description/info-row boxes + grid overflow fix; Row Height column/toolbar removed, replaced with `BID_SHEET_ROW_HEIGHT_IN` (0.40); Date Loaded column removed; version bump to v6.3 (corrected from an erroneous v7.0) |
| `starting-bid-list.php` | committed (`94fd61d`) | Mirrors the final OPEN/PREMIUM Value-alone rule; header comment updated |
| `test.html` | committed (`94fd61d`) | 3 suites rewritten (not just added), 3 new suites added, all executable assertions independently re-verified in Node. Confirmed green by the user. |

---

## What was accomplished this session (checkpoint v6.2)

Continuation of the same-day v6.1 session. Two new bid-sheet features plus one iOS bug fix — note that this checkpoint's "reserve counts toward Open Bids" change was **reversed in the very next checkpoint (v6.3)** after a real bug report; see that write-up above for the full story. Read this section for historical context only, not as the current rule.

### 1. Favicon — apple-touch-icon added for iOS
**Symptom reported:** the favicon didn't appear on iPhone/iPad. **Root cause:** iOS Safari ignores `<link rel="icon">` entirely — it only reads `<link rel="apple-touch-icon">`, which this app never had. **Fix:** added the tag to `<head>`, wired `updateFavicon()` to keep it in lockstep with the regular favicon (same Club Favicon URL setting, no new field). **Known limitation, explicitly flagged in a test assertion:** the current default image (`Images/ETCClogoWhiteBackground.png`) is 150×116px and non-square, below Apple's 180×180 square guidance — no image-editing tooling was available in this environment to produce a proper one, so iOS may crop/pad it imperfectly. Shows something now rather than nothing, but isn't crisp.

### 2. "Open Bids" — new bid-sheet category (first version, later revised)
New Settings → Auction Setup → "Open Bids" threshold (default $35): items at or below it get a $1 (or reserve, if the reserve itself was also ≤ threshold under this version's rule) opening bid with no preprinted increments. **This session's rule for what counted as "open"** — a reserve counted toward the check, not just an unreserved low-value item — **was reversed in v6.3**; see that write-up for why. The Settings UI, `DEFAULT_SETTINGS.openBidMax`, and the general shape of the feature (fixed row 1, blank rows 2-20) all survived into the current version unchanged; only the membership rule changed.

### 3. Bid ladder — every preprinted row forced strictly higher than the last
Real bug: rounding a sub-$1 increment could collapse consecutive rows onto the same dollar figure (e.g. a $2 reserve stepping by $0.20 printed `2, 2, 2, 3, 3...`). Fixed with a per-row guard (`if (prevAmt !== null && amt <= prevAmt) amt = prevAmt + 1`) rather than rounding the increment up globally, specifically so already-correct ladders keep their exact amounts. This guard is unchanged in the current (v6.3) version of the algorithm.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`6be9812`) | apple-touch-icon + `updateFavicon()` sync; Open Bids setting (first version, later revised) + rising-ladder guard; version bump to v6.2 |
| `starting-bid-list.php` | committed (`6be9812`) | Open Bids rule mirrored (first version, later revised) |
| `test.html` | committed (`6be9812`) | New suites for apple-touch-icon and the first Open Bids version (the latter later rewritten in v6.3). Confirmed green by the user. |

---

## What was accomplished this session (checkpoint v6.1)

Small, single-topic follow-up in the same day as the v6.0 session below — the user asked "where is the favicon," which surfaced a real duplicate-fallback bug while answering.

### 1. Real bug — two disagreeing hardcoded favicon fallbacks
**How it surfaced:** answering "where is the favicon" required tracing all the code paths that touch it, which turned up an inconsistency: the `<head>` `<link rel="icon">` tag and `getClub().favicon` ([index.html:6348](index.html:6348)) both defaulted to `Images/ETCClogoWhiteBackground.png`, but `updateFavicon()` ([index.html:6263](index.html:6263), pre-fix) carried its own separate hardcoded fallback, `Images/ETCC-Logo.ico`. With no `clubFavicon` setting configured, the tab icon's actual value depended on which code path ran last — the `.png` on first paint from the static tag, then potentially the `.ico` once settings loaded and `applyBranding()`/`updateFavicon()` ran.

**Fix:** `updateFavicon()` no longer carries its own literal — it now reads `link.href = faviconUrl || getClub().favicon`, deferring to `getClub()` as the single source of truth. Also removed the `type="image/png"` attribute from the static `<head>` tag, since Settings → Club Branding → Club Favicon URL can point the href at any format (`.ico`/`.svg`/etc.) and a hardcoded png type would misdescribe those overrides.

**Where the favicon can be changed:** Settings → Club Branding → **Club Favicon URL** (`#inp-club-favicon` → `clubFavicon` setting, applied via `applyBranding()`/`updateFavicon()`).

### 2. `test.html` updated, confirmed green
New suite `'Favicon — unified default fallback (v6.1)'` (4 assertions) covering the root cause, the fix, the `<head>` tag's type-attribute removal, and that `getClub()` remains the sole place the default path is defined. Deployed via `.\deploy.ps1 test.html`, confirmed green by the user before the checkpoint.

### 3. Checkpoint v6.1 (minor version bump)
Straightforward minor bump from v6.0 via `.\bump-version.ps1` (no `-Major` requested).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`be653c4`) | `updateFavicon()`'s duplicate literal removed, defers to `getClub().favicon`; `<head>` icon tag's `type="image/png"` dropped; version bump to v6.1 |
| `test.html` | committed (`be653c4`) | 1 new suite / 4 assertions. Confirmed green by the user before the checkpoint. |
| `PROJECT_STATUS.md` | this file, being committed now | continuity doc, not app code |

---

## What was accomplished this session (checkpoint v6.0)

Four independent threads, all driven by the user reviewing the live app and reporting what they saw. Two were new bid-sheet requirements; two were real UI bugs. Everything landed in one v6.0 checkpoint.

### 1. Real bug — Home Auctions panel empty until a hard refresh
**Symptom reported:** logging in at https://etccapps.com/apps/sam/ showed a splash screen with no data ("No auctions yet"), and only `Ctrl+Shift+R` followed by re-entering the password produced the real screen. The user noted their other apps don't behave this way. The console screenshot showed several `401` responses from `api.php` plus `get_all: Unauthorized`.

**Root cause (a render-ordering bug, not an auth bug):** the top-level `init()` IIFE in `index.html` calls `updateHomeAuctionPanel()` / `renderAuctionsList()` unconditionally at page load. At that moment the user is not yet logged in, and `get_all` is deliberately **not** in `api.php`'s `$publicActions` list, so it correctly returns 401 and the panel renders against empty data. On a successful password entry, `initializePasswordScreen()`'s `checkPassword()` *did* re-sync the real data via `syncFromKeyValueDB()` — but then only called `refreshHomeMetrics()`, which repaints the four small stat tiles and **nothing else**. The auctions panel/list was never re-rendered, so it stayed stuck on its pre-login empty render. A hard refresh "fixed" it only because by then the PHP session cookie was already valid, letting the *pre-login* boot fetch succeed on that second load. The 401s in the console were therefore expected/by-design, not the fault.

**Fix:** both login success paths now call `updateHomeAuctionPanel()` and `renderAuctionsList()` immediately after the post-login `syncFromKeyValueDB()` / `refreshHomeMetrics()` — `checkPassword()` (normal password screen) and the maintenance-screen `submit()` handler. Display layer only; no auth/session logic touched. Both functions are pure re-renders already called elsewhere (`archiveAuction()`, `openAuction()`), so calling them again is idempotent.

### 2. New feature — "Open Bids" category on bid sheets
**Request:** support two types of bid sheet based on the item's value *when no reserve is specified*. New setting **"Open Bids"** = the maximum declared value that counts as open-bid, default **$35.00**. Open-bid items get a $1 starting bid and no bid increments; every other item's sheet is unchanged.

**Design decision made by the user** (asked explicitly, since it changes a physical printout): of three options for the Bid Amount column, the user chose **"first row shows $1, rows 2-20 blank."** Not a blank column with a "Starting Bid: $1" header box, and not all 20 rows reading $1. Nothing else on the sheet changed — no extra info box was added.

**Implementation, all in `index.html`:**
- `DEFAULT_SETTINGS` gains `openBidMax: 35`, grouped with the other bid-sheet defaults.
- Settings → **Auction Setup** card gains `#inp-open-bid-max`, directly below Bid Count.
- Settings load reads `s.openBidMax ?? 35`; the save builder writes `openBidMax: parseFloat(...) || 0`. The `??` (not `||`) matters — it preserves an explicit `0`.
- `printBiddingSheets()`: `const isOpenBid = reserveRaw === 0 && itemValueRaw <= openBidMax;` → `startingBid = 1`, `incrementAmount = 0`, and the row renderer emits an empty `<td>` for `idx > 0`.

**Semantics worth knowing:**
- An item **with** a reserve is never open-bid, no matter how low its value.
- The threshold is **inclusive** ($35 exactly still qualifies at the default).
- **Setting Open Bids to 0 disables the category** for anything with a declared value. Blank/invalid input also saves as 0, matching how `squarePct`/`squareFee` already behave.
- An item with **neither value nor reserve** falls into the open-bid category. This is a strict improvement — previously such an item produced a sheet with 20 rows all reading `$0`.

### 3. `starting-bid-list.php` aligned with the new bid math
The standalone Starting Bid List computes its own starting bid in PHP and would have kept showing 30%-of-value for open-bid items, contradicting the printed sheet. On the user's go-ahead it now applies the identical rule:
```php
$isOpenBid = $reserve <= 0 && $value <= $openBidMax;
$startingBid = $isOpenBid ? 1 : ($reserve > 0 ? $reserve : ($value * $startingBidPct / 100));
```
- `$openBidMax` is read from `sam_settings` using `isset(...) && ... !== ''` — the strict `!==` lets an integer `0` through as "disabled", matching the JS `??` behavior, while a missing/blank value falls back to 35.
- Parsed via `(float)preg_replace('/[^0-9.]/', '', ...)`, mirroring the JS `parseMoney()` helper, so a setting stored as `"$35.00"` resolves to 35 rather than 0.
- **The nested ternary is deliberately parenthesized** — PHP 8 fatals on unparenthesized nested ternaries, and this file could not be linted locally (no `php` on PATH; see open item #7).
- A header comment now names `printBiddingSheets()` as the function this must stay in sync with. See open item #6 — this is now a two-implementation rule.

### 4. Real bug — preprinted bid rows could repeat the same amount
**Request:** "make sure every preprinted bid row is larger than the previous row."

**Root cause:** amounts were built as `Math.round(startingBid + i * incrementAmount)`. When the increment rounded to under $1, consecutive rows collapsed onto the same dollar figure — e.g. a **$2 reserve** steps by 10% = $0.20 and printed `2, 2, 2, 3, 3, 3…`. Only reachable for low-value reserved items (unreserved low-value items are now open-bid and print blank rows anyway).

**Fix:** a per-row guard — `if (prevAmt !== null && amt <= prevAmt) amt = prevAmt + 1;`. Chosen deliberately **over** rounding the increment up globally (`Math.max(1, Math.round(inc))`), because the guard leaves already-correct ladders byte-identical. Verified: `$40 → 12,16,20,24,28`, `$150 → 45,56,66,77,87,98` (keeps its uneven 11/10 spacing from the 7% rate), `$500 reserve → 500,535,570,605` — all unchanged. Only genuinely broken ladders shift (`$2 reserve → 2,3,4,5,6,7`).

### 5. Real bug — Developer nav row closed the drawer out from under its own submenu
**Request:** "when the developer password is validated, show the menu bar on the left."

**Root cause:** the off-canvas nav IIFE attached `closeNav` to **every** `.nav-item` — including `#nav-developer`. But that row doesn't navigate anywhere; `toggleDeveloperMenu()` expands `#nav-developer-submenu` *in place*. So clicking it removed `body.nav-open` and slid the drawer off-screen, while `submitAuthPassword()` dutifully expanded the submenu inside the now-hidden drawer. The menu was working the whole time — it was just off-screen.

**Fix:** exempt `#nav-developer` from that handler (`if (el.id === 'nav-developer') return;`). No ancestor of that element matches the `.nav-item, a` selector, so skipping the element itself fully suppresses the close. Submenu entries (Configuration / Settings / Change Log / API / Import Members) still close the drawer, since those *are* real navigation targets.

**Two deliberate scoping choices:** (a) this also fixes the **already-authenticated** path — within the 30-minute settings-auth window the drawer used to close with no password prompt to blame; (b) the fix lives in the nav click handler rather than forcing the drawer open inside `submitAuthPassword()`, because that function *also* gates deleting an auction and deep-links to developer screens, where popping the drawer open would cover the screen the user just asked for. Verified safe: `#auth-modal` is z-index 2000 vs `#nav` at 200 and `#nav-backdrop` at 150, so the modal renders cleanly above an open drawer.

### 6. `test.html` updated, confirmed green
Four new suites (24 assertions), plus the login-fix suite relabeled from v5.4 to v6.0 (a v5.4 checkpoint landed from a different session mid-stream, so the original label would have been wrong).

The bid-sheet suite is **genuinely executable**, not documentation-style: it carries a local `bidLadder()` mirror of `printBiddingSheets()`'s arithmetic (copied rather than imported, since the real function builds an entire print document via `openPrintWindow()`) and asserts against real computed output — open-bid detection, the $1 opening bid, strict monotonicity across 16 value/reserve combinations, the sub-$1 collapse fix, and a no-regression check pinning the $40/$150/$500 ladders to their exact prior amounts.

**That paid off immediately:** it caught a false assertion in the tests themselves. The claim "Open Bids = 0 disables the category entirely" is wrong — a $0/undeclared item still qualifies at 0, because `0 <= 0` holds. The assertion was corrected to "…for every item with a declared value" rather than the behavior being changed, since a $0 item landing in the open-bid category is the desirable outcome (it's what avoids the old all-`$0` sheet).

### 7. Checkpoint v6.0 (major bump, user-requested)
Invoked as `/ETCCSAMCheckpoint v6.0`. Current version was **v5.4** (not v5.3 — a "Donated Items table zoom" checkpoint had landed from a separate session in between), so `.\bump-version.ps1 -Major` reached exactly v6.0. All `deploy.ps1` calls succeeded first try this session, including the `.php` file.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`7f31481`) | Login re-render fix (2 paths); Open Bids setting (`DEFAULT_SETTINGS` + Auction Setup UI + load/save); `printBiddingSheets()` open-bid branch and strictly-rising ladder guard; `#nav-developer` exempted from the drawer-close handler; version bump to v6.0 |
| `starting-bid-list.php` | committed (`7f31481`) | Open-bid rule mirrored from the JS; `$openBidMax` read from `sam_settings` with 0-disables semantics; header comment cross-referencing `printBiddingSheets()` |
| `test.html` | committed (`7f31481`) | 4 new suites / 24 assertions (executable bid-ladder tests + Open Bids settings, Starting Bid List alignment, nav drawer); login suite relabeled v5.4 → v6.0. Confirmed green by the user before the checkpoint. |
| `PROJECT_STATUS.md` | this file, being committed now | continuity doc, not app code |

---

## What was accomplished in the v5.2 / v5.3 sessions (Donated Items table layout)

These two checkpoints never got their own write-up (the `/ETCCSAMEnd` run that would have covered them didn't happen before the v5.4 session took over the doc). Recorded briefly here so the version history has no gap — all of it is layout-only work on the Step 1 Donated Items table (`#items-table` in `index.html`):

- **v5.2** — rebalanced column widths after long donor addresses from `add-item.php` made the table read badly: Description widened 360px → 660px, and Value / Reserve / Donor Name / Donor Email narrowed (90→70, 90→70, 260→180, 260→200). Table `min-width` tracked the changes.
- **v5.3** — increased the table's scroll container from `max-height:258px` to `600px` so roughly 15+ rows are visible before scrolling starts. Same checkpoint also carried a pending `starting-bid-list.php` fix that had been sitting uncommitted: `white-space:nowrap` on Category/Donor Name was letting long content push past their intended share and squeeze Description, so that table moved to a fixed `<colgroup>` (Item # 7%, Category 24%, Starting Bid 8%, Donor Name 14%, Description 47%) with wrapping, and the card widened 700px → 900px.

---

## What was accomplished this session (checkpoint v5.4)

Two threads: diagnosing a set of Home-screen metric questions the user raised (no code bugs found, one piece of user education plus confirming an existing tool covered the need), then a small new feature (table zoom).

### 1. Home-screen metrics investigation — no bugs found
The user asked three follow-up questions about the Home screen's metric tiles (`refreshHomeMetrics()` in `index.html`):

- **"Bidders: 17" vs. Registrations screen showing 25.** Traced both to the same source — `Bidders.getAll()` → `DB.getBidders()` → `localStorage['sam_{auctionId}_bidders']` — so they should never disagree. Likely explanations given to the user: the Home tile only recalculates when `navigate('home')` fires `refreshHomeMetrics()`, so it can go stale if you register more bidders without revisiting Home; or transient drift from the dual-layer localStorage+MySQL sync (see `[[project_data_sync_architecture]]`). User was advised to hard-refresh and compare again; no follow-up report came back in this session, so **this discrepancy is not confirmed resolved** — if it resurfaces, start from this explanation rather than re-diagnosing from scratch.
- **"Paid & Picked Up: 5" despite "Winners Recorded: 0" and the Pay & Pickup screen itself saying "No winners recorded yet."** Root cause identified: the tile counts raw entries in `sam_payments` (`Object.values(payments).filter(p => p.paid).length`) regardless of whether a winner currently exists for that bidder — so these were **orphaned payment records** left over from before winners got cleared/reset for this auction. No live "winners → clear their payments too" cascade exists.
- **User asked to add a "Clear Payments" Developer Tools action.** Checked `index.html`'s Developer Tools card first — a `btn-clear-payments` button labeled **"Clear Pickup & Pay"** already exists (line ~1859), wired to `clearScopedData('payments', 'pickup & pay records')` and respecting the "Clear scope" auction dropdown above it. **No code was added** — the user was pointed at the existing button/flow instead (Developer Tools → set Clear scope to the current auction → Clear Pickup & Pay).

No files changed for this thread.

### 2. New feature — Donated Items table zoom in/out
The user asked for the ability to zoom in/out on the Donated Items table (Step 1 screen, `#items-table`). No existing zoom mechanism existed anywhere in the app (confirmed via grep for "zoom").

Implementation in `index.html`:
- Added a zoom control group (− / percentage label / + / Reset) to `#items-card`'s card-header toolbar, right of the 🖨 Print button.
- Wrapped the table's scroll container with `id="items-table-wrap"` (previously an unlabeled `<div style="overflow:auto;max-height:600px;">`) as the zoom target.
- New functions: `applyItemsZoom(pct)` sets `#items-table-wrap.style.zoom = pct/100` and updates the `#items-zoom-label` text; `zoomItemsTable(delta)` reads/writes the current percentage from `localStorage['sam_items_zoom']` (default 100), steps by 10 per click, clamps to **60%–150%**, and `delta === 0` (the Reset button) forces exactly 100%.
- Zoom **persists across reloads**: the app-init block (same block that calls `TableKit.initAll()` / `fixAllStickyHeaders()` / `applyBranding()`) now also calls `applyItemsZoom(parseInt(localStorage.getItem('sam_items_zoom'), 10) || 100)` on load.
- Deliberately used the CSS `zoom` property directly on the wrapper `<div>`, not a TableKit option — `table.css`/`table.js` are untouched, per the project's "never modify TableKit files" rule. `zoom` is Chromium/Edge-native; this is an internal admin tool so that's an acceptable tradeoff, but it won't work in Firefox if that's ever a real requirement — worth flagging if a user reports the buttons doing nothing.

### 3. `test.html` updated, confirmed green
Added a new suite `'Donated Items table — zoom in/out controls (this session)'` (4 assertions covering the toolbar controls, the clamped step/reset logic, the CSS-zoom-not-TableKit approach, and reload persistence). Deployed via `.\deploy.ps1 test.html`, then **confirmed green by the user** at https://etccapps.com/apps/sam/test.html before the checkpoint.

### 4. Checkpoint v5.4 (minor version bump)
Straightforward minor bump via `.\bump-version.ps1` (no `-Major` requested).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`57f1957`) | Zoom controls + `applyItemsZoom()`/`zoomItemsTable()`; version bump to v5.4. No Home-metrics code changes (investigation only). |
| `test.html` | committed (`57f1957`) | New zoom-feature suite added |
| `PROJECT_STATUS.md` | this update | continuity doc, not app code |

---

## What was accomplished this session (checkpoint v5.1)

Short session, two independent threads of work against `starting-bid-list.php` and a new (ultimately removed) form.

### 1. `starting-bid-list.php` — Member Name column replaced with Donor Name
A quick follow-up correction: the Starting Bid List's "Member Name" column (sourced from `etcc_member_name`, the ETCC member who submitted the item) was replaced with a **Donor Name** column (`donor_name`, the actual item donor) — the list is meant to credit donors, not track which club member did the data entry. Column order is now **Item # / Category / Starting Bid / Donor Name / Description**, same `white-space:nowrap` treatment as before, just on the renamed/re-sourced column. `test.html`'s `starting-bid-list.php` suite (originally written "v4.8 session," predating the actual v5.0 ship) had two assertions referencing "Member Name" — both corrected to describe the current Donor Name column, with a note that this was a deliberate correction, not a bug.

### 2. Built, then fully reverted — `silent-auction-form.php`, a public item-donation form with no member picker
The user asked for "a public silent auction form very similar to the item donation form." After a clarifying `AskUserQuestion` (they picked **"Duplicate/rename of `add-item.php`"**), this was first built as an exact file copy of `add-item.php` → `silent-auction-form.php`, deployed and working identically.

The user then asked to change its title to "Public Item Donation" and remove the ETCC Member Name/Email fields. A second `AskUserQuestion` (should this apply to `add-item.php` too, or just the new file?) confirmed **only `silent-auction-form.php`** — `add-item.php` stays as the internal, member-picker version; the new file was meant to be the fully public one. The following changes were made to `silent-auction-form.php` only:
- Subtitle changed to "Public Item Donation" (title tag and on-page `<div class="sub">`).
- Removed the `$members` SQL lookup/sort block entirely (no longer needed).
- Removed `etccMemberName`/`memberEmail` from `$values`, the POST-field read loop, the "required" validation, the `$newItem` write (`etcc_member_name` key dropped from the item record), the confirmation-email row list, and the email-To override logic (previously the selected member's email took priority as the confirmation recipient — with the field gone, `emailTo` now always uses the Settings-configured `donationEmailTo` address).
- Removed the ETCC Member Name `<select>`, its "— choose —" member-list `<option>`s, the read-only Member Email `<input>`, and the `samFillMemberEmail()` JS that synced them.
- Updated the file's top comment block to describe it as a public-audience duplicate of `add-item.php` with the member fields stripped out, rather than reusing `add-item.php`'s original comment verbatim.

**Then immediately reverted**: the user said "remove the new form." Verified via grep that nothing else in the codebase referenced `silent-auction-form.php` (it was never wired into `index.html` or any other page — always a standalone bookmarkable URL, same as `add-item.php`), then:
- Deleted it from the live server directly via an FTP `DELE` command (same approach used historically to remove `donate-item.php` — `deploy.ps1` has no built-in remote-delete helper, so this was a manual `curl ... -Q "DELE silent-auction-form.php"` against `ftp.etccapps.com`), confirmed with a follow-up `curl` HEAD-equivalent request that `https://etccapps.com/apps/sam/silent-auction-form.php` now 404s.
- Deleted the local file.
- Since the file was created and removed within the same session **before ever being committed**, `git status` shows no trace of it — nothing to revert or clean up in git history.

**Net effect on `add-item.php` and `test.html`**: none — `add-item.php` was never touched, and `silent-auction-form.php` never had regression-suite coverage added (it existed only briefly, mid-session), so no test assertions needed removing either.

### 3. `test.html` updated, confirmed green
Only the two stale "Member Name" assertions in the `starting-bid-list.php` suite needed correcting (see #1 above) — no new suite was needed since the `silent-auction-form.php` detour left no lasting code to cover. Deployed via `.\deploy.ps1 test.html`, then **confirmed green by the user** at https://etccapps.com/apps/sam/test.html before the v5.1 checkpoint.

### 4. Checkpoint v5.1 (minor version bump)
Straightforward minor bump via `.\bump-version.ps1` (no `-Major` flag requested this time, unlike the prior v5.0 session).

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `starting-bid-list.php` | committed (`214ef54`) | Member Name column → Donor Name column |
| `test.html` | committed (`214ef54`) | Two stale "Member Name" assertions corrected to Donor Name |
| `index.html` | committed (`214ef54`) | Version bump to v5.1 only |
| `silent-auction-form.php` | created, then deleted (local + live server) — never committed | Public item-donation form with no member picker; built, refined, then removed same-session at explicit request. See write-up above if a similar request comes in again. |
| `PROJECT_STATUS.md` | this update | continuity doc, not app code |

---

## What was accomplished this session (checkpoint v5.0)

Built entirely from a single fresh-start feature request that then went through many small rounds of live, iterative feedback (each a one-line follow-up). Summarized in final end-state order, not literal chat order.

### 1. New feature — `starting-bid-list.php`, a printable Starting Bid List
The user asked for "a page listing items donated" with Print and Done buttons, showing item number, category, description, and a starting bid (reserve, or Starting Bid % × value if no reserve).

**First attempt (superseded within the session):** built as an in-app JS function `printStartingBidList()` on the Donated Items screen (`index.html`), wired to a new "🖨 Starting Bid List" toolbar button — generated the report into a popup window via `document.write()`, the same pattern `printDonatedItemsList()` already uses.

**Final design (what's actually shipped):** the user then asked to remove that button and said they needed a **URL** for the list instead. Since the popup-window approach has no bookmarkable address, this was rebuilt as a real standalone PHP page — `starting-bid-list.php` — following the exact pattern `add-item.php` already established:
- No password gate, no login (same explicit convention as `add-item.php`) — public, reachable directly at **https://etccapps.com/apps/sam/starting-bid-list.php**.
- Reads live data **server-side from the SQL `sam_store` table**, not from localStorage: looks up `sam_current_auction` to pick the right `sam_{auctionId}_items` key (falling back to `sam_items`), decodes that JSON blob, and reads `sam_settings` for `startingBidPct`.
- The in-app popup version (`printStartingBidList()` JS function and its toolbar button) was fully deleted from `index.html` once the standalone page replaced it — no orphaned code left behind, since it was a same-session addition-then-replacement rather than a persisted feature being retired.

### 2. Iterative refinement of `starting-bid-list.php` (all same-session follow-ups)
In the order requested:
- **Column order**: started as Item # / Category / Description / Starting Bid, then Starting Bid was moved to directly after Category (Item # / Category / Starting Bid / Description).
- **Member Name column added**, sourced from `etcc_member_name` (the same field `add-item.php`'s "ETCC Member Name" dropdown writes), inserted between Starting Bid and Description. Final column order: **Item # / Category / Starting Bid / Member Name / Description**.
- **Print sizing**: "make print wider, make form narrower" — interpreted as: constrain the on-screen content to a narrower column (`max-width` on the content) while widening the printed page. First implementation switched print to landscape orientation; a later request ("make form modular" — user clarified via `AskUserQuestion` this meant **"not full page"**) reverted print back to **portrait**, keeping the printed table the same narrower width as the screen view rather than stretching it edge-to-edge.
- **Nowrap columns**: Item #, Category, and Member Name given `white-space:nowrap` so they never wrap onto a second line (Starting Bid and Description still wrap normally).
- **Header text**: changed to `<h2>Silent Auction - Donated Items</h2>` with an intro line "The following items have been donated by our members." A "Printed: `<date/time>`" line was added and then removed again per a direct follow-up ("remove printed date and time from form").
- **Done button behavior**: originally `window.close()` with a `setTimeout` fallback to `location.href='index.html'` (matching `add-item.php`'s Done button, for the case where the tab wasn't opened via `window.open()` and can't be closed by script). Simplified to **`window.close()` only** per explicit request ("done should closed form") — this page is always reached directly by URL, so the index.html fallback wasn't wanted.
- **Floating card layout**: "make form floating page" — clarified via `AskUserQuestion` (user picked **"Card with shadow"**) to mean a centered white card (rounded corners, `box-shadow`, `max-width:700px`) floating over a light-gray page background, matching a common modern-form aesthetic. `@media print` strips the background/shadow/border-radius and expands the card to full width so the printed page doesn't waste ink on decoration that only makes sense on screen.

### Gotcha for future sessions: two `AskUserQuestion` clarifications were needed this session
Two requests in a row ("make form modular", "make form floating page") were ambiguous enough on their own that guessing wrong would have meant redoing the work — both were disambiguated with `AskUserQuestion` before implementing, rather than guessing. If a short, vague styling request comes in for this page again, it's worth pattern-matching against this history before assuming what it means.

### 3. `test.html` updated to match, confirmed green
Added one new suite, **`starting-bid-list.php — standalone Starting Bid List page (v4.8 session)`** (9 assertions), covering: the standalone/no-login page itself, the server-side `sam_store` data source, final column order, the Starting Bid formula (reserve-or-percentage, matching the bid-sheet convention), the nowrap columns, the header text change, the floating-card layout, portrait print sizing, and the Done button's close-only behavior. (Suite name says "v4.8" — written before the checkpoint version number was decided; the actual shipped version is v5.0. Harmless, but if a future session is grepping `test.html` by version number, note the mismatch.)

No stale assertions needed fixing — the in-app popup button that was briefly added to `index.html` and then removed left no net trace requiring a test update.

Deployed via `.\deploy.ps1 test.html`, then **confirmed green by the user** at https://etccapps.com/apps/sam/test.html before the v5.0 checkpoint proceeded.

### 4. Checkpoint v5.0 (major version bump)
The user explicitly asked to bump to **5.0** (not a minor bump) — done via `.\bump-version.ps1 -Major` mid-session, ahead of the formal `/ETCCSAMCheckpoint` invocation. The checkpoint skill correctly detected the version was already bumped and did not double-bump.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `starting-bid-list.php` | new, committed (`5245137`) | Standalone public "Starting Bid List" page — see above for full column/layout history |
| `index.html` | committed (`5245137`) | Net change is just the version bump to v5.0 — the `printStartingBidList()` function and its toolbar button were added and then fully removed within this same session, so there's no trace of the popup-window approach left in the file |
| `test.html` | committed (`5245137`) | One new suite added (9 assertions) for `starting-bid-list.php`; no stale assertions found |
| `PROJECT_STATUS.md` | this update | continuity doc, not app code |

---

## What was accomplished this session (checkpoint v4.7)

Short, incremental session driven by the user reviewing the live Donated Items screen after the v4.6 checkpoint and flagging two follow-on issues. Nothing here was requested up front — it was found live, in order:

### 1. Removed the orphaned Add Item modal (resolves prior open item #3)
The v4.6 session had switched "+ Add Item" to open `add-item.php` in a new tab, leaving the old in-app modal (`#add-item-modal`) and its three functions (`openAddItemModal()`, `closeAddItemModal()`, `saveAddItemModal()`) orphaned but not deleted, per the project's "flag, don't delete" convention. This session confirmed via grep that nothing else in `index.html` referenced them, then deleted the modal's `<div>` block and all three functions outright.

### 2. Real bug — Donor Name overflow bleeding into Donor Email column
**Symptom reported (via screenshot):** the Donor Email cell for a long-donor-name row ("Wilderness Trail Distillery, Attn. Grayson Yaden") showed visually garbled/overlapping text. First assumed to be a data problem (bad paste, hidden Unicode bidi characters) or a screenshot/tooltip artifact — the user then confirmed via Edit mode that the underlying data was completely clean (`Grayson.Yaden@campari.com`), which ruled that out.

**Root cause, found by comparing sibling `<td>` styling:** in `refreshItemsTable()` (`index.html`), the Description and Category `<td>`s explicitly set `white-space:normal;word-break:break-word;vertical-align:top`, but the Donor Name and Donor Email `<td>`s (originally two lines down) had no such style — so under `table-layout:fixed`, a long Donor Name overflowed past its column's fixed width and visually overlapped the Donor Email cell next to it. This wasn't a data bug or a screenshot artifact at all; it only became visible once a sufficiently long donor name/address was entered (the `add-item.php` workflow introduced in v4.6 made long addresses common for the first time).

**Fix:** added the same `white-space:normal;word-break:break-word;vertical-align:top` styling to the Donor Name and Donor Email `<td>`s in `refreshItemsTable()`. Also widened both columns in `#items-table`'s `<colgroup>` (Donor Name 140px→260px in two steps per user follow-up request; Donor Email 190px→260px), bumping the table's `min-width` from 1576px→1766px to match.

### 3. Real bug — Donor Name/Email edit-mode inputs clipped long text
Once the *display* mode wrapped correctly, the *edit* mode (triggered by clicking "Edit") still used single-line `<input type="text">` elements for Donor Name and Donor Email in `editItemByNumber()`, which clip/scroll long values instead of wrapping — inconsistent with Description, which already used a `<textarea>`. Changed both to `<textarea>`, matching Description's pattern. This required a follow-on fix in `saveItemEdit()`: it read the new values via `querySelector('input')`, which no longer matched anything once the elements became `<textarea>`s (would have silently kept stale values on save) — updated to `querySelector('textarea')` for both fields.

### 4. `test.html` updated to match, confirmed green
- Corrected the now-stale v4.6-session assertion that claimed `#add-item-modal`/`openAddItemModal()` "still exist in the DOM but are no longer wired to any button" — now notes they were fully removed in this session.
- Added a new suite, `Donated Items table — orphaned Add Item modal removed, columns widened, donor overflow fixed (v4.7 session)`, covering all three fixes above.
- Deployed via `.\deploy.ps1 test.html`, then **confirmed green by the user** at https://etccapps.com/apps/sam/test.html before the checkpoint proceeded.

### Files touched this session
| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`61f0345`) | Add Item modal removal; Donor Name/Email `<td>` wrap fix + column widening; Donor Name/Email edit-mode `<textarea>` fix + matching `saveItemEdit()` fix; version bumped to v4.7 |
| `test.html` | committed (`61f0345`) | One stale assertion corrected, one new suite added (4 assertions); confirmed green by the user |
| `PROJECT_STATUS.md` | committed separately (`9c09762`, then this update) | continuity doc, not app code |

---

## What was accomplished this session (checkpoint v4.6)

This was a long, iterative session driven entirely by incremental UI/UX requests against the Step 1 screen and `add-item.php`, plus two real bugs discovered and fixed along the way. Summarized in the order the underlying *design* ended up in, not the literal chat order (which zig-zagged through several false starts — see "Design detours" below).

### 1. `deploy.ps1` — cache-busting on every deploy
Added `Update-CacheBust()`: stamps a fresh `?v=<epoch-seconds>` query string onto the four static asset links in `index.html` (`css/table.css`, `css/toolbar.css`, `js/table.js`, `js/toolbar.js`), replacing any existing `?v=...` rather than stacking (idempotent, same pattern as `Update-Version`'s deploy-date stamp). Wired into both the single-file (`.\deploy.ps1 index.html`) and full-deploy code paths, alongside `Update-Version`. Purpose: force browsers to fetch fresh CSS/JS instead of a stale cached copy after every deploy.

### 2. Step 1 — "Item Load" → "Donated Items" screen overhaul
The single biggest change this session. Final end state:
- **Renamed everywhere**: page header, Home screen workflow card (title + subtitle "View donated items"), the `screenNames` map used by `navigate()`, and the in-app User Manual's Step 1 section all now say "Donated Items" instead of "Item Load"/"Load Item Emails".
- **Gmail email-scan workflow retired from view, not deleted.** The Gmail connection status strip, "no client ID" warning, and the Inbox Emails card (Scan Emails / View All / Delete All, `#email-table`) are now wrapped in a `display:none` container. They could not be removed outright: several `document.getElementById('btn-scan').addEventListener(...)`-style calls elsewhere have **no null-guard**, so deleting the elements would throw at script load and break the whole page. `#btn-load-items` ("Update Items") is hidden the same way, for the same reason.
- **The separate "Donated Items" modal (`#donated-items-modal`) was removed entirely** — it was a short-lived design from earlier this session (a full-screen overlay mirroring Loaded Items, with its own checkbox/bulk-delete UI) that got superseded once the user clarified they wanted the *existing* Loaded Items table itself to carry that behavior, not a separate view. `openDonatedItemsModal()`, `closeDonatedItemsModal()`, `renderDonatedItemsTable()`, `selectAllDonatedItemRows()`, `deleteCheckedDonatedItems()`, and the `ITEM_EDIT_COLS_DONATED` column map are all gone. The original `#items-table`/`#items-tbody` (card header now says "Donated Items", was "Loaded Items") carries the checkbox column and bulk delete directly.
- **Checkbox column + bulk delete on the one remaining table**: every row has a `.item-row-check` checkbox (`data-item-number="..."`); a header "check all" checkbox (`#items-check-all-th`) toggles all rows via `selectAllItemRows(checked)`. **Gotcha discovered and fixed**: `TableKit.init()` rebuilds every `<th>`'s contents on init, which wiped this checkbox out on every `refreshItemsTable()` call (same issue the View All modal already had to work around). Fixed by re-injecting the checkbox into the header cell immediately after `TableKit.init(document.getElementById('items-table'))` runs.
- **Per-row Delete button removed** — Actions column is now Edit-only (+ conditional View when a scanned-email match exists, a vestige of the old workflow, harmless). Deletion is now bulk-only via a **Delete Item** button (`deleteCheckedItems()`), which collects checked `data-item-number`s, confirms once, filters them out, saves, and cleans up any orphaned winner records — mirrors what `deleteItemByNumber()` already did per-row.
- **"Date Loaded" column removed** entirely — from the `<colgroup>`/`<thead>`, from `refreshItemsTable()`'s row template, and from `ITEM_EDIT_COLS_MAIN`'s column-index map (shifted left by one: `category:3, desc:4, value:5, reserve:6, donorName:7, donorEmail:8, donorPhone:9, actions:10`). Table is now 12 columns (was 13, including the checkbox).
- **"View Categories" and "View All" buttons removed** from the toolbar (functions still exist, just unused here — `showViewAll` is still called elsewhere for emails/bid-sheets).
- **"Delete All" removed, replaced with a "🖨 Print" button** (`printDonatedItemsList()`) — opens a print-friendly list of all donated items via the same `openPrintWindow()` pattern already used by `printBiddersList()`/`printPaymentTable()`/etc. (`deleteAllItems()` is still defined but now unused.)
- **"Check All" / "Uncheck All" toolbar buttons removed** — the header checkbox is now the only toggle-all control (added originally alongside the buttons, then the buttons were removed once the header checkbox worked correctly).
- **"+ Add Item" now opens `add-item.php` in a new tab** (`window.open('add-item.php', '_blank')`) instead of the in-app modal — see open item #3 above re: the now-orphaned modal.
- **`add-item.php`'s "Cancel" button relabeled "Done"**, and its behavior changed from "just reload the same blank form" to `window.close(); setTimeout(() => location.href='index.html', 150);` — tries to close the tab (it was opened via script, so this works), falling back to navigating to `index.html` if the browser refuses.

### 3. Real bug #1 — multi-tab data loss (add-item.php inserts silently erased)
**Symptom reported:** an item added via the `add-item.php` form didn't show up in the app, even after navigating away and back.

**Root cause, found by tracing the actual save/sync code** (not by guessing): `navigate()` always calls `persistItemLoadScreenToDB()` when leaving the item-load screen, which does a **full overwrite** (`DB.saveItems()` → `save_items` action → deletes and re-inserts every row for that auction, in both the SQL `items` table and the `sam_store` key-value blob) using whatever items array is currently in the browser's memory. If `add-item.php` (now opened in a separate tab) inserted an item while the original SAM tab still held an older in-memory copy, switching back to that tab and navigating *anywhere* would push the stale array back out — silently erasing the item `add-item.php` had just written. This risk was actually already flagged in `add-item.php`'s own header comment, written back when Add Item only had an in-app-modal path with no separate-tab conflict — switching Add Item to open in its own tab (this session) is what made the risk real.

**Fix:** the app now re-syncs from the database automatically whenever the tab regains focus, reusing the existing `PageVisibilityManager.init()` `visibilitychange` handler (previously only used for session-timeout detection). Its "page visible again, session still valid" branch now also calls `syncFromKeyValueDB()`, then `refreshItemsTable()`/`refreshMetrics()` if the Donated Items screen is currently active — so switching back from the `add-item.php` tab refreshes the in-memory copy *before* any subsequent navigation can push a stale version back out.

### 4. Real bug #2 — Reserve Amount silently hidden when it had a "$" prefix
**Symptom reported:** after adding an item via `add-item.php`, its Reserve field wasn't populating in the table.

**Root cause:** `add-item.php`'s client-side `formatCurrency()` prepends a `$` to the Reserve Amount before submission (e.g. `"$75"`). Four separate render sites decided whether to show the Reserve column using a bare `parseFloat(item.reserve_amount) > 0` — and `parseFloat("$75")` returns `NaN` in JavaScript (it does not skip a leading `$`), so any reserve value carrying that prefix was silently treated as blank/zero. Items loaded the old way (Gmail scan, plain numeric reserve) never had a `$` prefix, which is exactly why this bug went unnoticed until `add-item.php` became the primary entry point this session.

**Fix:** switched all four sites — `refreshItemsTable()` (Donated Items table), `printDonatedItemsList()`, the Announce Winners table, and the View All-equivalent render path — plus the bid-sheet's `hasReserve` flag (governs whether the Reserve info box/column appears on printed bid sheets) to use the existing `parseMoney()` helper, which already strips `$`/`,` before parsing.

### 5. Total Value metric — reserve-or-value fallback
Per explicit user request: `refreshMetrics()`'s Total Value calculation now sums, per item, the **Reserve** amount if it's set and non-zero, otherwise the **Value** amount (previously always summed Value regardless of Reserve).

### 6. Settings screen — Email Field Mapping card and Clear Emails button removed
- **Email Field Mapping card removed entirely**: the card, `renderFieldMapTable()`/`editFieldMapRow()`/`saveFieldMapRow()`/`removeFieldMapRow()`, the `LOADED_ITEMS_COL`/`SELECT_BY_OPTIONS` constants, its `#field-map-table` CSS, and its User Manual mention are all gone. `DB.getFieldMap()`/`saveFieldMap()` (the data-layer functions) were left alone, since the still-present (orphaned) Gmail-scan email parser calls `DB.getFieldMap()`.
- **Gotcha caught before it shipped**: `renderFieldMapTable()` was called unconditionally on every Settings-screen load — leaving that call in place after removing its target table would have thrown (`tbody.innerHTML` on `null`) and broken the rest of Settings' load sequence (auction dropdown, favicon, settings-password field, etc.). Removed the call.
- **"Clear Emails" button removed** from Developer Tools, along with its `document.getElementById('btn-clear-emails').addEventListener(...)` call (same unguarded-null-ref risk, same fix).
- **Gmail OAuth card explicitly kept** — user was asked directly and confirmed keeping it, since it's still load-bearing for Announce Winners → Email Winners (see open item #4 above).

### Design detours worth knowing about (in case they look like unfinished work)
- Early in the session, "replace the Item Load form with the donated items form" went through **three rounds of clarification** before landing on the final design — the user first meant the Donated Items screen's manual-entry fields (not `add-item.php`, not the old Gmail-parsed fields), which is why the very first version of this change made the Add Item modal (already existing but previously unwired) the primary entry point, *before* a later request switched it to open `add-item.php` in a new tab instead.
- A short-lived intermediate design added a **separate** "Donated Items" modal (mirroring Loaded Items with its own checkbox/bulk-delete UI) before the user clarified they wanted that behavior folded directly into the existing Loaded Items table instead, with no separate modal. If a future session finds this confusing in git history, that's why — it was corrected within the same session, not left half-done.

---

## Checkpoint procedure (⚠️ CHANGED 2026-09-16 — read before assuming the old "user always runs tests" rule)

1. Update `test.html` for whatever changed (skip if nothing test-relevant changed).
2. `.\deploy.ps1 test.html` if it changed.
3. **Run the suite automatically** — open the Browser pane on the live `https://etccapps.com/apps/sam/test.html` (`preview_start` with that `url`), click "▶ Run All", wait a few seconds, then read the Passed/Failed/Total and any failure details via `get_page_text`. **No need to ask the user to run it or wait for their confirmation** — this is a direct, explicit instruction from the user ("from now on: automatically run the test without user intervention and deploy"). Only fall back to asking the user if the Browser-pane run itself fails to load or errors out.
4. If red: fix the failing code/test, redeploy `test.html`, re-run automatically. If green: proceed immediately — no stopping to ask.
5. `.\bump-version.ps1` (minor bump by default; `-Major` flag for major bumps) **if not already bumped earlier in the session** — check the footer span first, don't double-bump. Then `.\deploy.ps1 index.html` (and any other changed files individually).
6. `git add` the changed files (never `git add -A`), commit with a `Checkpoint vX.Y: <short description>` message, `git push`. Commit and push **without asking**.
7. Report the commit hash, version, live URL, and pass count back to the user.

Deploying individual files (`.\deploy.ps1 <file>`) happens continuously after every code change, **without being asked** — separate from the commit/checkpoint step. Never commit on every deploy — only at an explicit "checkpoint". Bare "test" (no other words) still means **update** the regression suite only — that specific command wasn't changed by this session's instruction, only the checkpoint flow's own test-running step was.

**A bare "commit, and push"** (or invoking the separate, lighter-weight `ETCCCheckpoint` skill — a global agent skill, not part of this repo, shared with the CarShow project) skips the version bump and the test-green gate entirely. Use judgment on which the user actually means.

**Known issue #1 (RESOLVED as of 2026-09-16 — a working automated-run method was found; the OLD method is still dead).** For a long time, automated regression test running from a Claude Code session was blocked — a bot-check/403 page intercepted any CDP-automated browser (specifically the local headless `node run-tests.js` Puppeteer script) hitting the live site (Hostinger-hosted; earlier write-ups of this doc misattributed this to "Cloudflare" — this project has no Cloudflare in front of it). **What changed:** the Browser pane tools (`preview_start`/`computer`/`find`/`get_page_text`) are a *different* automation mechanism than that local script, and hitting the same live `test.html` URL through them is **not** blocked — confirmed 2026-09-16 by actually running it: all 1228 tests executed and reported real pass/fail counts, no bot-check page. **`node run-tests.js` is still dead — don't retry it.** The fix was switching to the Browser-pane method, not fixing the old script. This is now the standing checkpoint method (see step 3 above) per the user's explicit request to automate it.

**Known issue #2 (RESOLVED as of 2026-08-31, v6.7/v6.8 session):** `deploy.ps1`'s FTP upload used to run via `curl.exe` and would intermittently fail (`curl: (56) response reading failed` or `curl: (18) ... got 450`) — with the file sometimes not actually updating on the server despite (or alongside) the reported failure, or despite a reported *success*. Root cause identified this session: `curl.exe` on this machine uses the Windows Schannel TLS backend, which has a bug against this Hostinger FTPS server — the transfer completes fully but curl can't read the server's final control-channel response. **`deploy.ps1` no longer uses curl at all** — `Deploy-File` now uses `System.Net.FtpWebRequest`, verified working across single-file and full-deploy modes with real `Last-Modified`-header checks. See open item #14 above for full detail. If deploy failures resurface, they are a *different* issue now — don't reach for the old curl/Hostinger-File-Manager workaround by reflex.

---

## Architecture notes not yet in CLAUDE.md

- **`rowCheckboxOffset(tr)`** (`index.html`, module-scope, near `ITEM_EDIT_COLS_MAIN`): returns `1` if a row's first `<td>` contains any `<input type="checkbox">`, else `0`. Used by `editItemByNumber()`/`saveItemEdit()` so cell-index lookups stay correct regardless of which table (Loaded/Donated Items' now-permanent checkbox, or View All's optional one) is being edited. Since Donated Items' checkbox is now permanent (not conditional), `ITEM_EDIT_COLS_MAIN`'s indices are consistently "un-shifted" positions with `off` always adding the checkbox offset on top for that table.
- **`VIEW_ALL_SELECTABLE.items.stripLeadingCol`** (`index.html`, `showViewAll()`): the View All modal clones `#items-table`'s rows and normally adds its *own* selection checkbox. Since Loaded/Donated Items now has a permanent leading checkbox of its own, this flag strips the source table's own checkbox cell/header from the clone first (guarded to skip the single-cell empty-state row) so the two don't stack into a doubled, misaligned column.
- **`PageVisibilityManager`** (`index.html`): originally only handled session-timeout-on-return-from-hidden. Its "page visible again" branch now also re-syncs from the database (`syncFromKeyValueDB()`) and refreshes the Donated Items screen if active — see "Real bug #1" above. Any future feature that opens app-adjacent pages in a separate tab (like `add-item.php`) benefits from this automatically; no per-feature wiring needed.
- **`deploy.ps1`'s `Update-CacheBust()`** (new this session): stamps `?v=<epoch-seconds>` onto `css/table.css`, `css/toolbar.css`, `js/table.js`, `js/toolbar.js` in `index.html` at deploy time. Idempotent — re-running replaces the existing `?v=...` rather than appending a duplicate.
- **`add-item.php`'s "Done" button** now does `window.close()` first, falling back to `location.href='index.html'` after 150ms if the tab wasn't closable (e.g. reached directly rather than via `window.open()` from the app).

---

## Files touched this session

| File | Status | Notes |
|---|---|---|
| `index.html` | committed (`8b32733`) | Donated Items screen overhaul (see above), multi-tab sync fix (`PageVisibilityManager`), Reserve Amount display fix (4 sites + bid-sheet `hasReserve`), Total Value reserve-or-value fallback, Settings Field Mapping/Clear Emails removal |
| `add-item.php` | committed (`8b32733`); also manually uploaded twice mid-session due to FTP failures | "Cancel" → "Done" button relabel + `window.close()`-then-fallback behavior |
| `deploy.ps1` | committed (`8b32733`) | New `Update-CacheBust()` function, wired into both deploy paths |
| `test.html` | committed (`8b32733`) | Substantially rewritten via `/ETCCSAMTest`: 3 fully-stale suites rewritten (old Donated Items modal design, Step 1 button positioning, item-editing cell indices), 6 new suites added (add-item.php Done button, multi-tab sync fix, Reserve Amount bug fix, Total Value fallback, Settings removals, deploy.ps1 cache-busting). Confirmed green by the user before the v4.6 checkpoint. |
| `PROJECT_STATUS.md` | this file, being committed now | continuity doc, not app code |
