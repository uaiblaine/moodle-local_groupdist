moodle-local_groupdist
====================

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-local_groupdist/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/uaiblaine/moodle-local_groupdist/actions/workflows/ci.yml?query=branch%3Amain)

Bulk-distribute course participants into the groups you select — with role and
cohort filters, deterministic preview, per-group seat capacities and
profile-field affinity.

The plugin adds a **"Distribute participants"** action to the course group
management page (*Participants → Groups*). Select existing groups, choose who
takes part (role, cohort, only active enrolments), how they are ordered
(randomly or alphabetically, like core's auto-create groups), optionally keep
users with the same profile-field value together or apart, respect each
group's **Seats** capacity with optional overbooking — then inspect a preview
(totals, warnings, sample members per group) before anything is written.

The plugin owns no database tables. "Seats" and "Location" are provisioned as
core **group custom fields** (Moodle 4.3+ subsystem), memberships are written
through core's `groups_add_member()` (stamped with `component =
'local_groupdist'` for auditability), and previews are recomputed
deterministically from a seed instead of being stored — a fingerprint check
aborts the apply if enrolments changed since the preview. Large runs
(> 500 memberships) apply in the background with a progress indicator.

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

- Moodle 5.1 or later (tested up to Moodle 5.2)

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
   alphabetical), affinity by profile field (keep together / keep apart) and
   whether to respect the **Seats** field, with optional overbooking.
3. Press **Preview distribution**: totals, warnings (capacity overflows,
   users without a value in the affinity field, infeasible keep-apart
   constraints) and a sample of members per group, paged five groups at a
   time up to 25.
4. Press **Apply distribution**. Small runs are applied immediately; large
   runs are queued as a background task with a progress bar. If enrolments or
   memberships changed since the preview, the apply refuses and asks for a
   fresh preview.

Settings
--------

- `cleanupfieldsonuninstall` (default off): when enabled, uninstalling the
  plugin removes the provisioned group custom fields together with the values
  stored for every group.

Capabilities
------------

- `local/groupdist:distribute` — run distributions. Cloned from
  `moodle/course:managegroups`; carries `RISK_PERSONAL` because the preview
  lists profile-field values of the candidates.

Limitations
-----------

- Affinity (keep together / keep apart) considers the users being distributed
  in the current run; profile values of members a group already has are not
  taken into account.
- The preview pages through at most 25 groups; applying always covers every
  selected group.

Design documentation, including the approved UI mockups, lives under
[`docs/`](docs/) (excluded from release packages).

License
-------

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

Copyright: 2026 Anderson Blaine
