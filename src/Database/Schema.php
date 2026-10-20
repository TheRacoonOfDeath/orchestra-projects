<?php

declare(strict_types=1);

namespace Orchestra\Projects\Database;

use wpdb;

/**
 * Creates and migrates the custom tables of orchestra-projects.
 *
 * Pieces and parts are referenced by their orchestra-library IDs. User IDs of participation and
 * assignments become NULL when the user is deleted, so the history stays complete but anonymous.
 */
final class Schema
{
    /** Increase whenever a table definition changes; dbDelta() applies the difference. */
    public const VERSION = 4;

    public const OPTION_VERSION = 'orchestra_projects_schema_version';

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function staffTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_projects_staff';
    }

    public function programTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_projects_program';
    }

    public function participationTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_projects_participation';
    }

    public function assignmentsTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_projects_assignments';
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return [$this->staffTable(), $this->programTable(), $this->participationTable(), $this->assignmentsTable()];
    }

    public function migrateIfOutdated(): void
    {
        $installed = \get_option(self::OPTION_VERSION, 0);
        if (\is_numeric($installed) && (int) $installed >= self::VERSION) {
            return;
        }

        $this->migrate();
    }

    /**
     * Drops all tables. Only for uninstalling.
     */
    public function drop(): void
    {
        foreach ($this->tables() as $table) {
            $this->wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        \delete_option(self::OPTION_VERSION);
    }

    private function migrate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $this->wpdb->get_charset_collate();

        // dbDelta() is picky: one column per line, two spaces after PRIMARY KEY, KEY instead of INDEX.
        \dbDelta("CREATE TABLE {$this->staffTable()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  role varchar(20) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY project_role_user (project_id,role,user_id),
  KEY user_id (user_id)
) {$charsetCollate};");

        \dbDelta("CREATE TABLE {$this->programTable()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  piece_id bigint(20) unsigned NOT NULL,
  position int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY project_piece (project_id,piece_id),
  KEY piece_id (piece_id)
) {$charsetCollate};");

        \dbDelta("CREATE TABLE {$this->participationTable()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL,
  responded_at datetime DEFAULT NULL,
  changed_by bigint(20) unsigned DEFAULT NULL,
  changed_at datetime DEFAULT NULL,
  member_comment text DEFAULT NULL,
  organizer_note text DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY project_user (project_id,user_id),
  KEY user_id (user_id)
) {$charsetCollate};");

        // piece_id is the piece of the part, stored for the rule "one part per piece and member".
        \dbDelta("CREATE TABLE {$this->assignmentsTable()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL,
  piece_id bigint(20) unsigned NOT NULL,
  part_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY project_piece_user (project_id,piece_id,user_id),
  KEY part_id (part_id),
  KEY user_id (user_id)
) {$charsetCollate};");

        $this->removeFees();
        \update_option(self::OPTION_VERSION, self::VERSION, true);
    }

    /**
     * Version 3 (orchestra-projects 0.23 and 0.24) stored project fees and who had paid them. The
     * feature was removed; dbDelta() does not drop columns, so this does.
     */
    private function removeFees(): void
    {
        $table = $this->participationTable();
        $column = $this->wpdb->get_var($this->wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'fee_paid'));
        if ($column !== null) {
            $this->wpdb->query("ALTER TABLE {$table} DROP COLUMN fee_paid");
        }
        \delete_post_meta_by_key('orchestra_projects_fee');
    }
}
