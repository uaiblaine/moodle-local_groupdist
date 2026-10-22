moodle-local_groupdist
====================

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-local_groupdist/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/uaiblaine/moodle-local_groupdist/actions/workflows/ci.yml?query=branch%3Amain)

Bulk-distribute course participants into the groups you select — with role and
cohort filters, deterministic preview, per-group seat capacities, affinity
rules and an audit log of every applied distribution.

The plugin adds a **"Distribute participants"** action to the course group
management page (*Participants → Groups*). Select existing groups, choose who
takes part (role, cohort, only active enrolments), how they are ordered
(randomly or alphabetically, like core's auto-create groups), optionally keep
users together or apart by a profile field, a cohort or an existing course
group (several rules, in priority order), respect each
group's **Seats** capacity with optional overbooking — then inspect a preview
(totals, warnings, sample members per group) before anything is written.

Every applied distribution is recorded in an **audit log** kept in the
plugin's own tables (see *Data stored* below), readable as the course's
*Distribution log* report. Everything else lives in core: "Seats" and
"Location" are provisioned as core **group custom fields**, memberships are
written through core's `groups_add_member()` (stamped with `component =
'local_groupdist'`), and previews are recomputed deterministically from a seed
instead of being stored — a fingerprint check aborts the apply if enrolments
changed since the preview. Large runs (> 500 memberships) apply in the
background with a progress indicator.

A **Bulk edit groups** action complements the flow: a table of the selected
groups for editing seats, location and any other group custom field inline,
with mass-apply, overbooking indicators and a per-row modal carrying every
setting of core's own group edit form.

Before the button appears
-------------------------

- The course must have at least one group (the button lives on
  *Participants → Groups* and enables once groups are selected).
- The user needs the `local/groupdist:distribute` capability (granted to
  editing teachers and managers by default, cloned from
  `moodle/course:managegroups`).
- JavaScript must be enabled (the button is injected client-side; the page
  offers no server-side extension point).

Requirements
------------

- Moodle 4.5 LTS (branch `MOODLE_405_STABLE`; releases `v4.5-rN`). Other Moodle versions have their own branches.

Installation
------------

Install the plugin like any other plugin to folder `/local/groupdist`.

See http://docs.moodle.org/en/Installing_plugins for details on installing
Moodle plugins.

After installation the plugin provisions two group custom fields in a
"Distribution" category: **Seats** (number) and **Location** (text). No
further configuration is required; teachers fill the fields per group under
the group settings.

Usage
-----

### Bulk edit groups

Select groups under *Participants → Groups* and press **Bulk edit groups**:
a table of the selected groups (picture, name, ids, member count and every
group custom field) with inline editing, a mass-apply control for the seats
field, an empty-seats filter, per-user collapsible columns and a dynamic
red indicator when a group's member count exceeds its seats. Saves travel
through a chunked web service — only changed cells are sent, at most 200 per
request. The per-row **Edit** button opens the group's settings in a modal
carrying every element of core's own group edit form: name, ID number,
description, enrolment key, group membership visibility and participation,
messaging, the current picture and a new-picture upload, plus all custom
fields. As on core's form, visibility and participation are read-only once
the group has members.

### Distribute participants

1. Go to *Participants → Groups* in a course, select one or more existing
   groups and press **Distribute participants**.
2. Choose the options: member source (role, cohort, only active enrolments,
   ignore users already in the selected groups), allocation order (random or
   alphabetical), affinity rules (keep together / keep apart by a profile
   field, a cohort or a course group, in priority order) and whether to
   respect the **Seats** field, with optional overbooking. A profile field is
   offered only when you may see it: custom fields by their visibility,
   department and institution when the site lists them in *Show user identity*
   and you hold `moodle/site:viewuseridentity`, city and country unless *Hide
   user fields* hides them and you lack `moodle/course:viewhiddenuserfields`.
3. Press **Preview distribution**: totals, warnings (capacity overflows,
   users without a value in the affinity field, infeasible keep-apart
   constraints) and a sample of members per group, paged five groups at a
   time up to 25.
4. Press **Apply distribution**. Small runs are applied immediately; large
   runs are queued as a background task with a progress bar. If enrolments or
   memberships changed since the preview, the apply refuses and asks for a
   fresh preview.

### Distribution log

*Distribution log*, under the course's *Reports*, lists every applied run (who ran
it, when, the options, the outcome) and opens each one: the groups as written,
each participant with their rule values and the reason they were placed there,
with searches over participants and groups. Values of a rule whose field the
reader may not see are masked.

Settings
--------

- `cleanupfieldsonuninstall` (default off): when enabled, uninstalling the
  plugin removes the provisioned group custom fields together with the values
  stored for every group.
- `auditretentiondays` (default 365): audit runs older than this are deleted by
  the daily *Clean up expired distribution audit runs* task; 0 keeps the log
  forever.
- `maxaffinityrules`: the most affinity rules one distribution may carry.

Capabilities
------------

- `local/groupdist:distribute` — run distributions. Cloned from
  `moodle/course:managegroups`; carries `RISK_PERSONAL` because the preview
  lists profile-field values of the candidates.
- `local/groupdist:viewauditlog` — read the distribution log of a course
  (editing teachers and managers by default); carries `RISK_PERSONAL` because
  the log keeps each participant's rule values.

Data stored
-----------

The plugin keeps personal data in two tables, the audit log:

- `local_groupdist_run`, one row per applied distribution: the course, the
  user who applied it, the options and rules (with their labels as they were),
  the outcome and the time.
- `local_groupdist_run_user`, one row per participant of a run: the user, their
  value for each rule at apply time (profile field values, cohort and group
  membership flags), the planned group and whether the membership was written.

Rows are kept for `auditretentiondays` days (365 by default; 0 keeps them
forever), removed with the course, and pseudonymised when a user is deleted. The plugin also writes the group
memberships of each run into core's `groups_members` table, stamped with its
component, and stores one user preference (the columns collapsed on the bulk
edit table).

All of it is covered by the Privacy API: a data request exports the user's
runs, participations and the memberships the plugin added; a deletion request
removes those memberships and pseudonymises the audit rows (the user id is
zeroed and the values are blanked), so the counts of the remaining
participants stay intact.

Limitations
-----------

- Affinity (keep together / keep apart) considers the participants of the
  current run. With *Ignore users already in the selected groups* off, a
  participant already in a selected group stays there and their values bind
  the others; members the role or cohort filter leaves out are not taken into
  account.
- The preview pages through at most 25 groups; applying always covers every
  selected group.

Design documentation, including the approved UI mockups, lives under
[`docs/`](docs/) (excluded from release packages).

Translations
------------

The English strings ship in the package. The Brazilian Portuguese translation
is maintained in this repository (`lang/pt_br`) and reaches sites through
[AMOS](https://lang.moodle.org/), as the plugins directory requires; it is left
out of the release package.

License
-------

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

Copyright: 2026 Anderson Blaine
