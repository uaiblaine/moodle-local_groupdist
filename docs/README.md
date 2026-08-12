# local_groupdist — design documentation

## Approved UI mockups

The two HTML files under [`mockups/`](mockups/) are the self-contained,
navigable prototypes the flow was designed and approved against
(2026-08-11). Open them in any browser; they carry no external dependencies
and follow Moodle Boost's visual language. Labels inside the mockups are in
Brazilian Portuguese because they mirror the approved `pt_br` UI strings —
the shipped plugin resolves all labels through language packs (`en` +
`pt_br`).

| File | Screen |
|------|--------|
| [`mockups/step1-options.html`](mockups/step1-options.html) | Step 1 — distribution options form (moodleform sections, affinity + seats controls with enable/disable behaviour) |
| [`mockups/step2-preview.html`](mockups/step2-preview.html) | Step 2 — preview (recap chips, stat tiles, warnings, group cards with capacity meters, simulated lazy loading in pages of 5 up to the 25-group cap) |

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
