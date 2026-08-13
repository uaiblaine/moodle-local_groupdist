# local_groupdist — design documentation

## Approved UI mockups

The HTML files under [`mockups/`](mockups/) are the self-contained,
navigable prototypes the flow was designed and approved against.
Open them in any browser; they carry no external dependencies
and follow Moodle Boost's visual language. Labels inside the mockups are in
Brazilian Portuguese because they mirror the approved `pt_br` UI strings —
the shipped plugin resolves all labels through language packs (`en` +
`pt_br`).

| File | Screen |
|------|--------|
| [`mockups/step1-options.html`](mockups/step1-options.html) | Step 1 — distribution options form (moodleform sections, affinity + seats controls with enable/disable behaviour; approved 2026-08-11) |
| [`mockups/step2-preview.html`](mockups/step2-preview.html) | Step 2 — preview (recap chips, stat tiles, warnings, group cards with capacity meters, simulated lazy loading in pages of 5 up to the 25-group cap; approved 2026-08-11) |
| [`mockups/bulk-edit.html`](mockups/bulk-edit.html) | Bulk edit groups (approved v3, 2026-08-12) — table of selected groups with inline custom-field editing, mass-apply for seats, dynamic overbooking indicator on the members column, empty-seats highlighting + filter, collapsible columns menu, floating tooltips on truncated names/idnumbers, per-row "Edit" modal wrapping core's group settings, responsive card layout on mobile |
| [`mockups/affinity-rules.html`](mockups/affinity-rules.html) | Multi-rule affinity (approved 2026-08-13) — rule builder (repeatable rows summed with an explicit "AND" connector, list position = priority, drag + button reordering), member enrolment options (new "include future-start enrolments" checkbox, distinct from suspended), preview explainability alternatives A (per-rule badges on group cards), B (per-participant "why here?") and C (per-rule report with clusters/pairs), and the audit log screens (run list under the course Reports section, run detail reading the stored snapshot, reader-side masking, pseudonymised participants, deleted-group marker) |

The mockups link to each other the same way the real pages flow ("Preview
distribution" / "Back and adjust"). The bottom action bar in both mockups
represents core's `\core\output\sticky_footer`, which the implemented pages
use.

## Design decisions worth knowing

- **Deterministic recompute, no plan storage.** Every preview page call and
  the apply step recompute the full allocation from (options + seed). A
  sha256 fingerprint over the candidate id set and each group's
  (id, seats, current members) is returned by the preview and re-verified at
  apply: any concurrent enrolment/membership change aborts the apply instead
  of silently writing a plan the teacher never saw. Consequence: the plugin
  stores no user data (privacy `null_provider`).
- **"Seats"/"Location" are core group custom fields**, provisioned
  idempotently under a lock into a plugin-managed category. Uninstall
  cleanup is an admin opt-in (`cleanupfieldsonuninstall`), patterned on
  availability_competency's `cleanuponcompetencydeletion`.
- **Entry point is a JS-injected submit button with `formaction`** on
  /group/index.php — the page has no server-side extension point, a button
  with `name="action"` would hit core's unknown-action exception, and GET
  navigation would overflow URL limits with hundreds of selected groups.
- **"Prevent last small group" was deliberately dropped**: core disables it
  in the fixed-group-count mode (the only mode here), and this allocator
  always balances group sizes within one member.
- **Affinity constraints apply to the users being distributed in the run**;
  values of existing group members are not considered (documented in the
  help strings).

## Multi-rule affinity decisions (approved 2026-08-13)

- **Flat ordered rule list, AND only.** Affinity becomes an ordered list of
  rules `(source, mode)` — source is a native user field, a custom profile
  field or a specific cohort; mode is keep-together or keep-apart. Every rule
  always applies (implicit AND); list position is the priority (splits and
  violations hit the lowest-priority rule first). Boolean OR/nesting was
  evaluated against core_availability and rejected: availability trees are
  per-user predicates, affinity rules are pair relations — OR-together forces
  transitive closure (giant clusters), OR-apart has no graph semantics, and
  mixed-mode OR is vacuous. Boolean logic remains reserved (schema version
  hinge `"v"`) for per-rule *scope matchers* in a future version, the one
  place it is well defined. No backward compatibility: the legacy
  `affinityfield`/`affinitymode` pair is removed everywhere.
- **Rule cap is a validation guardrail, not a performance boundary** (default
  10, admin-configurable). Allocation compute is ~linear in rules; the async
  trigger stays the apply write volume. The compute is never parallelised —
  the greedy engine is order-dependent and preview/apply must produce the
  same plan bit for bit.
- **Future-start enrolments option** (`includefuture`): includes enrolments
  that are active but not yet started — distinct from suspended, which stay
  out. Core's `get_enrolled_join()` cannot relax only the time window, so the
  plugin adds its own EXISTS predicate (with the SITEID exemption). Gated on
  `moodle/course:viewsuspendedusers`; inert when "only active" is unchecked.
- **Audit log = a snapshot, not references.** Every apply records who ran it,
  the ruleset with labels resolved at the time, each participant's per-rule
  values and per-member write outcome, in the plugin's first two DB tables.
  Explanations ("why here?") derive from the stored facts, never from
  replaying the engine. Privacy provider becomes full metadata + request +
  userlist with pseudonymising deletes; retention setting + cleanup task;
  purge on `course_deleted` (the recycle bin stores a backup file, not the
  course); entry lives in the course **Reports** section (`coursereports`
  settings-navigation container); the log travels in course backups only when
  "Include course logs" is ticked (excluded under anonymised backups).
- **sticky_footer carries action buttons only** — explanatory notes render in
  the page content area, never in the footer.
