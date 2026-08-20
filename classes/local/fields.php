<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_groupdist\local;

use core_customfield\api;
use core_customfield\category_controller;
use core_customfield\field_controller;
use core_group\customfield\group_handler;

/**
 * Provisioning and bulk reading of the plugin's group custom fields.
 *
 * The plugin owns no tables: "Seats" and "Location" are provisioned against
 * core's group custom field handler (component core_group, area group) and
 * their values live in {customfield_data}. Provisioning is idempotent and
 * serialised under a core Lock API lock, because neither customfield_field
 * nor customfield_category carries a unique index — two concurrent first
 * requests would silently create duplicate definitions.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fields {
    /** @var string Shortname of the seats (capacity) number field. */
    public const SHORTNAME_SEATS = 'groupdist_seats';

    /** @var string Shortname of the location text field. */
    public const SHORTNAME_LOCATION = 'groupdist_location';

    /** @var array|null Request cache of resolved field controllers keyed by shortname. */
    private static ?array $fieldcache = null;

    /**
     * Get the seats field, or null when it has not been provisioned (or was deleted).
     *
     * @return field_controller|null The field controller.
     */
    public static function get_seats_field(): ?field_controller {
        return self::find_field(self::SHORTNAME_SEATS, 'number');
    }

    /**
     * Get the location field, or null when it has not been provisioned (or was deleted).
     *
     * @return field_controller|null The field controller.
     */
    public static function get_location_field(): ?field_controller {
        return self::find_field(self::SHORTNAME_LOCATION, 'text');
    }

    /**
     * Reset the request-level field cache (used by tests and after provisioning).
     *
     * @return void
     */
    public static function reset_field_cache(): void {
        self::$fieldcache = null;
    }

    /**
     * Display name of the seats field as the admin actually named it.
     *
     * Custom field names are stored once at provisioning time (in the
     * provisioning admin's language) and are not translatable afterwards, so
     * every UI text referring to the field must echo the stored name instead
     * of hardcoding a translated "Seats"/"Vagas".
     *
     * @return string The formatted field name (lang-pack fallback when missing).
     */
    public static function get_seats_label(): string {
        $field = self::get_seats_field();
        return $field ? self::plain($field->get('name')) : get_string('fieldseats', 'local_groupdist');
    }

    /**
     * Display name of the location field as the admin actually named it.
     *
     * @return string The formatted field name (lang-pack fallback when missing).
     */
    public static function get_location_label(): string {
        $field = self::get_location_field();
        return $field ? self::plain($field->get('name')) : get_string('fieldlocation', 'local_groupdist');
    }

    /**
     * Format a stored field name for output, unescaped.
     *
     * Every consumer escapes for itself and would otherwise escape a second
     * time, so a field an admin named "Vagas & Lugares" reads "Vagas &amp;
     * Lugares" on screen. Measured on 5.2: the label reaches a
     * {{#str}} parameter, which the string helper renders through a double
     * stash before substituting it, and the lambda's own return is inserted
     * unescaped — so the page carried "Vagas &amp;amp; Lugares" while the
     * column header beside it, already fixed, carried "Vagas &amp; Lugares".
     *
     * The system context is deliberate: group custom fields are defined
     * site-wide (group_handler::get_configuration_context()), not per course.
     * The lang-pack fallback above needs no equivalent — a lang string is
     * never escaped on the way out either.
     *
     * @param string $name The stored field name.
     * @return string The formatted name, not HTML-escaped.
     */
    private static function plain(string $name): string {
        return format_string($name, true, [
            'context' => \core\context\system::instance(),
            'escape' => false,
        ]);
    }

    /**
     * Find one of the plugin's fields inside the group handler's area.
     *
     * Reads through {@see api::get_categories_with_fields()} directly instead of
     * the handler instance: the handler memoises its category list per request,
     * which would hide fields another request provisioned while we waited on the
     * lock. The api call is plain SQL every time.
     *
     * A field whose shortname matches but whose type differs (an admin created
     * it manually) is treated as unavailable rather than adopted.
     *
     * @param string $shortname The field shortname.
     * @param string $type The expected field type (number, text).
     * @return field_controller|null The field controller.
     */
    private static function find_field(string $shortname, string $type): ?field_controller {
        if (self::$fieldcache !== null && array_key_exists($shortname, self::$fieldcache)) {
            $field = self::$fieldcache[$shortname];
            return ($field && $field->get('type') === $type) ? $field : null;
        }

        self::$fieldcache = self::$fieldcache ?? [];
        self::$fieldcache[$shortname] = null;

        $categories = api::get_categories_with_fields('core_group', 'group', 0);
        foreach ($categories as $category) {
            foreach ($category->get_fields() as $field) {
                if ($field->get('shortname') === $shortname) {
                    self::$fieldcache[$shortname] = $field;
                    if ($field->get('type') !== $type) {
                        debugging(
                            "local_groupdist: field '{$shortname}' exists with unexpected type '" .
                                $field->get('type') . "', expected '{$type}' — feature disabled.",
                            DEBUG_DEVELOPER
                        );
                        return null;
                    }
                    return $field;
                }
            }
        }
        return null;
    }

    /**
     * Ensure the plugin's group custom fields exist, creating them when missing.
     *
     * Invoked from db/install.php, the tail of db/upgrade.php and lazily from
     * the distribution entry page, so an admin deleting the category simply
     * re-provisions on next use.
     *
     * @return void
     */
    public static function ensure_fields_exist(): void {
        if (during_initial_install()) {
            return;
        }

        // Fast path: both fields resolve (request-cached).
        if (self::get_seats_field() && self::get_location_field()) {
            return;
        }

        /* Provisioning is check-then-act and the customfield tables have no
           unique indexes, so concurrent first requests must be serialised. */
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_groupdist');
        $lock = $lockfactory->get_lock('provisionfields', 10);
        if (!$lock) {
            // Another request is provisioning right now; nothing to do here.
            return;
        }
        try {
            // Re-read fresh: the lock winner may have created the fields already.
            self::reset_field_cache();
            if (!self::get_seats_field()) {
                self::create_field(self::SHORTNAME_SEATS, 'number', get_string('fieldseats', 'local_groupdist'), [
                    'decimalplaces' => 0,
                    'minimumvalue' => 0,
                    'maximumvalue' => '',
                    'displaywhenzero' => '0',
                    'fieldtype' => '',
                ]);
            }
            if (!self::get_location_field()) {
                self::create_field(self::SHORTNAME_LOCATION, 'text', get_string('fieldlocation', 'local_groupdist'), [
                    'displaysize' => 50,
                    'maxlength' => 255,
                    'ispassword' => 0,
                    'link' => '',
                ]);
            }
        } finally {
            self::reset_field_cache();
            $lock->release();
        }
    }

    /**
     * Create one field inside the plugin's category, creating the category first if needed.
     *
     * @param string $shortname The field shortname.
     * @param string $type The customfield type (number, text).
     * @param string $name The display name (resolved in the provisioning admin's language;
     *   stored once — custom field names are not translatable after creation).
     * @param array $typeconfig Type-specific configdata entries.
     * @return void
     */
    private static function create_field(string $shortname, string $type, string $name, array $typeconfig): void {
        $handler = group_handler::create();

        if (!api::is_shortname_unique($handler, $shortname, 0)) {
            /* A field with this shortname exists somewhere the handler can see
               (possibly a shared field of the wrong type). Creating another one
               would collide in the group edit form element names. */
            debugging("local_groupdist: shortname '{$shortname}' already taken — field not created.", DEBUG_DEVELOPER);
            return;
        }

        $category = self::get_or_create_category();
        if (!$category) {
            return;
        }

        $config = $typeconfig + [
            'defaultvalue' => '',
            'defaultvalueformat' => FORMAT_PLAIN,
            'required' => 0,
            'uniquevalues' => 0,
            'locked' => 0,
            'visibility' => 2,
        ];

        $record = (object) [
            'type' => $type,
            'shortname' => $shortname,
            'name' => $name,
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'configdata' => json_encode($config),
        ];

        $field = field_controller::create(0, (object) ['type' => $type], $category);
        $handler->save_field_configuration($field, $record);
    }

    /**
     * Resolve the plugin's category, creating it when missing.
     *
     * The created category id is remembered in plugin config so renames by the
     * admin do not break the lookup; a stale id (category deleted) falls back
     * to creating a fresh category.
     *
     * @return category_controller|null The category controller.
     */
    private static function get_or_create_category(): ?category_controller {
        $categories = api::get_categories_with_fields('core_group', 'group', 0);

        $storedid = (int) get_config('local_groupdist', 'cfcategoryid');
        if ($storedid) {
            foreach ($categories as $category) {
                if ((int) $category->get('id') === $storedid) {
                    return $category;
                }
            }
        }

        $handler = group_handler::create();
        $categoryid = $handler->create_category(get_string('fieldcategory', 'local_groupdist'));
        set_config('cfcategoryid', $categoryid, 'local_groupdist');
        return category_controller::create($categoryid);
    }

    /**
     * Remove the provisioned fields (and the category, when it holds nothing else).
     *
     * Called from db/uninstall.php when the cleanupfieldsonuninstall setting is
     * enabled. Deleting a field cascades its {customfield_data} rows.
     *
     * @return void
     */
    public static function delete_provisioned_fields(): void {
        self::reset_field_cache();

        foreach ([self::SHORTNAME_SEATS => 'number', self::SHORTNAME_LOCATION => 'text'] as $shortname => $type) {
            $field = self::find_field($shortname, $type);
            if ($field) {
                api::delete_field_configuration($field);
            }
        }
        self::reset_field_cache();

        $storedid = (int) get_config('local_groupdist', 'cfcategoryid');
        if ($storedid) {
            $categories = api::get_categories_with_fields('core_group', 'group', 0);
            foreach ($categories as $category) {
                if ((int) $category->get('id') === $storedid && !$category->get_fields()) {
                    api::delete_category($category);
                }
            }
            unset_config('cfcategoryid', 'local_groupdist');
        }
    }

    /**
     * Bulk-read seats and location values for a set of groups in one query.
     *
     * Reads {customfield_data} directly by field id + instance id (both covered
     * by the unique instanceid/fieldid index) instead of the handler API: with
     * hundreds of groups the handler's per-instance context resolution is an
     * N+1 trap, and the whole flow is already gated on the distribute capability.
     *
     * @param array $groupids Group ids.
     * @return array Map of groupid => object with ?int 'seats' and ?string 'location';
     *   null seats means "no limit declared".
     */
    public static function get_group_values(array $groupids): array {
        global $DB;

        $result = [];
        foreach ($groupids as $groupid) {
            $result[$groupid] = (object) ['seats' => null, 'location' => null];
        }
        if (!$groupids) {
            return $result;
        }

        $seatsfield = self::get_seats_field();
        $locationfield = self::get_location_field();
        $fieldids = [];
        if ($seatsfield) {
            $fieldids[] = (int) $seatsfield->get('id');
        }
        if ($locationfield) {
            $fieldids[] = (int) $locationfield->get('id');
        }
        if (!$fieldids) {
            return $result;
        }

        [$fieldsql, $fieldparams] = $DB->get_in_or_equal($fieldids, SQL_PARAMS_NAMED, 'f');
        [$groupsql, $groupparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
        $rows = $DB->get_records_sql(
            "SELECT id, fieldid, instanceid, decvalue, charvalue
               FROM {customfield_data}
              WHERE fieldid {$fieldsql} AND instanceid {$groupsql}",
            $fieldparams + $groupparams
        );

        $seatsid = $seatsfield ? (int) $seatsfield->get('id') : 0;
        foreach ($rows as $row) {
            $groupid = (int) $row->instanceid;
            if (!isset($result[$groupid])) {
                continue;
            }
            if ((int) $row->fieldid === $seatsid) {
                $result[$groupid]->seats = ($row->decvalue === null) ? null : (int) $row->decvalue;
            } else {
                $result[$groupid]->location = $row->charvalue;
            }
        }
        return $result;
    }

    /**
     * Current member counts for a set of groups in one query.
     *
     * With $excludeseed set, memberships stamped by this plugin with that
     * itemid are not counted: an interrupted apply run then recomputes the
     * identical original plan on retry (its own partial writes are invisible)
     * and completes idempotently instead of tripping the staleness guard.
     *
     * @param array $groupids Group ids.
     * @param int $excludeseed Seed whose own memberships to ignore (0 = none).
     * @return array Map of groupid => int member count (0 for groups with no rows).
     */
    public static function get_member_counts(array $groupids, int $excludeseed = 0): array {
        global $DB;

        $result = array_fill_keys(array_map('intval', $groupids), 0);
        if (!$groupids) {
            return $result;
        }

        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
        $where = "groupid {$insql}";
        if ($excludeseed) {
            $where .= " AND (component <> 'local_groupdist' OR itemid <> :exseed)";
            $params['exseed'] = $excludeseed;
        }
        $counts = $DB->get_records_sql(
            "SELECT groupid, COUNT(*) AS cnt
               FROM {groups_members}
              WHERE {$where}
           GROUP BY groupid",
            $params
        );
        foreach ($counts as $row) {
            $result[(int) $row->groupid] = (int) $row->cnt;
        }
        return $result;
    }
}
