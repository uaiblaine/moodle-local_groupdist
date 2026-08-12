# Claude instructions for `local_groupdist`

This file is auto-loaded as context whenever Claude works in this plugin's
directory tree. **Fleet-wide standards live in `~/dev/CLAUDE.md`** (coding
style, CI gates, lang-string rules, the `mdl` environment, git rules) — do not
repeat them here. This file keeps only what is true for this plugin.

Plugin context: a Moodle **local** plugin ("Group distribution") that adds a
"Distribute participants" bulk action to the course group management page,
distributing enrolled users into the SELECTED existing groups with role/cohort
filters, autogroup-style allocation orders, profile-field affinity
(keep-together / keep-apart) and per-group seat capacity + overbooking. It
owns **no database tables**: "Seats"/"Location" are core *group custom
fields* it provisions, memberships live in core `{groups_members}`, and
previews are recomputed deterministically (seed + fingerprint) instead of
stored — hence privacy `null_provider`. Supports Moodle **5.1 through 5.2**
(`$plugin->requires = 2025100600`, `$plugin->supported = [501, 502]`).
CI is the moodle-an-hochschulen reusable workflow, one job per supported
branch in `.github/workflows/ci.yml` — **update those jobs when `supported`
changes**. Development happens on the m501/m502 stacks; this repo is mounted
via `stacks = auto` at `local/groupdist` (see `~/dev/moodle-dev/plugins.conf`).

## Commands

```sh
mdl ci moodle-local_groupdist            # full CI locally before any push
mdl phpunit m501 local_groupdist         # targeted tests (also run m502)
mdl behat m501 @local_groupdist          # Behat smoke tests
mdl grunt m501 local/groupdist           # rebuild amd/build (commit with src)
mdl purge m501                           # after PHP changes that affect output
```

## Code layout

```
distribute.php               Step 1+2 controller: options form POST target and
                             preview renderer (sticky_footer with apply/back)
apply.php                    Step 3: fingerprint re-check, inline vs adhoc
status.php                   Background apply progress (core task_indicator)
bulkedit.php                 Bulk edit table of the selected groups' custom
                             fields (gate: moodle/course:managegroups)
lib.php                      local_groupdist_user_preferences() (column prefs)
classes/
  hook_callbacks.php         before_footer_html_generation → injects the button
  local/options.php          Canonical option value object (form/WS/task shape)
  local/candidates.php       One-query candidate fetch (enrol+role+cohort+affinity)
  local/allocator.php        Pure deterministic engine (no DB) + typed warnings
  local/allocation.php       Allocator result value object
  local/distribution.php     Builder: groups+fields+counts+allocation+fingerprint
  local/applier.php          Chunked transactional writes via groups_add_member
  local/fields.php           Group custom field provisioning + bulk readers
  local/profilefields.php    Affinity field enumeration (visibility-filtered)
  external/get_preview.php   Paged preview WS (recomputes per call)
  external/save_group_fields.php Chunked bulk-edit save (dirty cells only,
                             MAX_CHANGES=200 per call; client chunks at 100)
  output/bulkedit_page.php   Table context builder (also refreshes one row
                             after the settings modal saves)
  form/group_settings_form.php Dynamic-form modal wrapping core group settings
  task/apply_distribution.php Adhoc apply with stored progress
  event/distribution_applied.php One event per applied run (no objecttable)
amd/src/index_button.js      Injected formaction submit button on group/index
amd/src/preview.js           Preview hydration + lazy load (pages of 5, cap 25)
templates/                   preview shell, stats tiles, group cards, skeleton,
                             sticky footer actions, selected-groups chips
docs/                        Approved HTML mockups + design decisions (export-ignored)
```

## Architecture gotchas

- **The injected button must stay a `type="submit"` with `formaction` and NO
  `name` attribute.** group/index.php throws `moodle_exception('unknowaction')`
  for any unknown `action` value (index.php:172), and GET navigation overflows
  URL limits with hundreds of `groups[]` ids. The POST carries `groups[]`,
  `id`, `sesskey` natively.
- **Hook guard order matters**: `before_footer_html_generation` runs on every
  page — check `pagetype === 'group-index'` first, then
  `pagelayout === 'standard'` (redirect interstitials keep the pagetype), then
  context/capability. `$PAGE->requires->css()` and `add_body_class()` throw at
  footer time; CSS lives in styles.css.
- **Determinism is the contract**: preview WS calls and the apply recompute
  the same allocation from (options + seed). Anything that would make
  `distribution::build()` non-deterministic (unordered iteration, time(),
  unseeded rand) breaks preview-vs-apply equality. The fingerprint must cover
  EVERY input the allocator's output is a function of: candidate ids, their
  sort keys (name/idnumber — they order the list AND the shuffle's input
  permutation), affinity values, and per-group (id, seats, current count,
  existing-member set). Adding an allocator input without adding it to
  `compute_fingerprint()` reopens the silently-different-plan hole the review
  caught. Apply and the adhoc task refuse on mismatch — the task must NOT
  throw on mismatch (adhoc failures are retried forever).
- **Interrupted applies resume via seed-stamped invisibility**: every
  recompute (counts, existing sets, the ignore-grouped exclusion) skips
  memberships stamped `component='local_groupdist' AND itemid = <this seed>`,
  so a retried adhoc run reproduces the original plan bit-identically, passes
  the fingerprint check and completes idempotently. Any new query feeding
  `distribution::build()` must apply the same exclusion or retries abort as
  "stale" with the remainder unwritten.
- **Candidate query**: `groups_get_potential_members()` is unusable here — it
  dies with `dml_exception('mixedtypesqlparam')` when asked for custom profile
  fields (MDL-70456) and materialises full user records. The own query uses
  `get_enrolled_join()` (which owns the SITEID front-page case — never inline
  the enrolment predicate), role assignments include parent contexts, and
  `onlyactive` is forced ON when the acting user lacks
  `moodle/course:viewsuspendedusers` (autogroup.php:100 parity).
- **Custom fields**: always read via `fields::` bulk helpers (direct
  `{customfield_data}` by fieldid+instanceid); the handler's
  `get_instances_data(..., returnall: false)` is an N+1 trap (one
  `get_record('groups')` per group). Provisioning is lock-serialised
  (customfield tables have no unique indexes) and re-reads through
  `\core_customfield\api` (uncached), not the memoising handler. Seats value
  reads use `get_value()`/raw `decvalue` — `export_value()` returns the
  `displaywhenzero` text for 0. `group_handler::create()` is safe on both
  branches, but its `reset_caches()` moved between 5.1 and 5.2 — don't call it.
- **Applier**: every write goes through `groups_add_member($group, $userobj,
  'local_groupdist', $seed)` — bypassing it skips events/hooks/cache
  invalidations core relies on; passing the user OBJECT (candidates select
  `u.deleted`) skips a per-member `get_record`. A `dml_write_exception` inside
  a transaction poisons it on PostgreSQL: the chunk catch rolls back and
  replays member-by-member WITHOUT a transaction. `groups_add_member` returns
  true for already-members (idempotent re-runs). In the replay, a caught
  `dml_write_exception` is ambiguous (duplicate key vs deadlock/lock timeout —
  same exception type): re-check `groups_is_member()` before counting it as
  added.
- **Affinity field visibility mirrors core** (`profile_field_base::is_visible`,
  listing case): ALL → everyone; TEACHERS → `moodle/site:viewuseridentity` at
  the course; PRIVATE/NONE → `moodle/user:viewalldetails` (NOT
  viewhiddendetails — teachers hold that by default and it would leak hidden
  fields). Raw `cohortid` inputs on the WS/apply are validated with
  `cohort_get_cohort($id, $context)` so hidden cohorts cannot be used as a
  membership oracle.
- **"Prevent last small group" is deliberately absent** — core disables it in
  fixed-group-count mode and this allocator balances within one member.
- **WS return structure is an allowlist**: preview data is rendered client-side
  only, from `get_preview` — a field added to the payload must be added to
  `execute_returns()` or `clean_returnvalue` silently strips it.
- **Bulk edit saves are payload-bounded by design**: only dirty cells travel,
  the client slices sequential chunks of 100 and `save_group_fields` rejects
  calls above `MAX_CHANGES` (200). Partial cell saves are safe because
  customfield data controllers early-return on absent `customfield_<shortname>`
  properties (`data_controller::instance_form_save`, property_exists check) —
  never "helpfully" fill in the other fields' properties, that would wipe them.
- **The privacy provider is preference-based, not null**: the collapsible
  columns store `local_groupdist_bulkedit_hiddencols` (declared in
  `lib.php:local_groupdist_user_preferences()` so the WS may set it). A new
  user preference means extending BOTH lib.php and the privacy provider.

## Testing notes

- `allocator_test` is pure `basic_testcase` — keep it DB-free.
- Provisioning tests must call `fields::reset_field_cache()` around
  `ensure_fields_exist()` (request-level static cache).
- Saving group field values in tests goes through
  `group_handler::create()->instance_form_save()` **as admin** — the handler
  silently drops fields the current user cannot edit.
- WS tests need `$_POST['sesskey'] = sesskey();` before
  `call_external_function()`.
- The `@covers`-in-docblock PHPUnit deprecations match core 5.1/5.2 style;
  they do not fail CI.

## When in doubt

Follow the patterns in existing files. The codebase is internally
consistent — if a new file feels like it matches no existing shape,
re-examine the approach.
