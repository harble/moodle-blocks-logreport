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

/**
 * Displays different views of the logs.
 *
 * @package    block_logreport
 * @copyright  2018 onwards Naveen kumar(naveen@eabyas.in)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_logreport;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/tablelib.php');

use report_log_table_log;

/**
 * @class dataprovider
 */
class dataprovider extends report_log_table_log {

    public function __construct($uniqueid = 'report_log', $filterparams = null) {
        parent::__construct($uniqueid, $filterparams);
        $this->filterparams = $filterparams;

        // Override headers with localizable strings.
        $headers = [];
        if (empty($filterparams->courseid)) {
            $headers[] = get_string('course', 'block_logreport');
        }
        $this->define_headers(array_merge($headers, [
            get_string('time', 'block_logreport'),
            get_string('fullnameuser', 'block_logreport'),
            get_string('relateduser', 'block_logreport'),
            get_string('context', 'block_logreport'),
            get_string('component', 'block_logreport'),
            get_string('eventname', 'block_logreport'),
            get_string('description', 'block_logreport'),
            get_string('origin', 'block_logreport'),
            get_string('ip', 'block_logreport'),
        ]));
    }

    /**
     * Override time column format to display as 年/月.日 时:分:秒.
     *
     * @param \stdClass $event
     * @return string Formatted time string.
     */
    public function col_time($event) {
        if (empty($this->download)) {
            $dateformat = '%Y/%m/%d %H:%M:%S';
        } else {
            $dateformat = '%Y/%m/%d %H:%M:%S';
        }
        return userdate($event->timecreated, $dateformat);
    }
    /**
     * Query the db. Store results in the table object for use by build_table.
     *
     * @param int $pagesize size of page for paginated displayed table.
     * @param bool $useinitialsbar do you want to use the initials bar. Bar
     * will only be used if there is a fullname column defined for the table.
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;
        $joins = array();
        $params = array();
        // If we filter by userid and module id we also need to filter by crud and edulevel to ensure DB index is engaged.
        $useextendeddbindex = !($this->filterparams->logreader instanceof logstore_legacy\log\store)
        && !empty($this->filterparams->userid) && !empty($this->filterparams->modid);

        $groupid = 0;
        if (!empty($this->filterparams->courseid) && $this->filterparams->courseid != SITEID) {
            if (!empty($this->filterparams->groupid)) {
                $groupid = $this->filterparams->groupid;
            }

            $joins[] = "courseid = :courseid";
            $params['courseid'] = $this->filterparams->courseid;
        }

        if (!empty($this->filterparams->siteerrors)) {
            $joins[] = "( action='error' OR action='infected' OR action='failed' )";
        }

        if (!empty($this->filterparams->modid)) {
            list($actionsql, $actionparams) = $this->get_cm_sql();
            $joins[] = $actionsql;
            $params = array_merge($params, $actionparams);
        }

        if (!empty($this->filterparams->action) || $useextendeddbindex) {
            list($actionsql, $actionparams) = $this->get_action_sql();
            $joins[] = $actionsql;
            $params = array_merge($params, $actionparams);
        }

        // Getting all members of a group.
        if ($groupid and empty($this->filterparams->userid)) {
            if ($gusers = groups_get_members($groupid)) {
                $gusers = array_keys($gusers);
                $joins[] = 'userid IN (' . implode(',', $gusers) . ')';
            } else {
                $joins[] = 'userid = 0'; // No users in groups, so we want something that will always be false.
            }
        } else if (!empty($this->filterparams->userid)) {
            $joins[] = "userid = :userid";
            $params['userid'] = $this->filterparams->userid;
        }

        if (!empty($this->filterparams->date)) {
            $joins[] = "timecreated > :date AND timecreated < :enddate";
            $params['date'] = $this->filterparams->date;
            $params['enddate'] = $this->filterparams->date + DAYSECS; // Show logs only for the selected date.
        }

        if (isset($this->filterparams->edulevel) && ($this->filterparams->edulevel >= 0)) {
            $joins[] = "edulevel = :edulevel";
            $params['edulevel'] = $this->filterparams->edulevel;
        } else if ($useextendeddbindex) {
            list($edulevelsql, $edulevelparams) = $DB->get_in_or_equal(array(\core\event\base::LEVEL_OTHER,
                                                                             \core\event\base::LEVEL_PARTICIPATING,
                                                                             \core\event\base::LEVEL_TEACHING),
                                                                        SQL_PARAMS_NAMED, 'edulevel');
            $joins[] = "edulevel " . $edulevelsql;
            $params = array_merge($params, $edulevelparams);
        }

        // Origin.
        if (isset($this->filterparams->origin) && ($this->filterparams->origin != '')) {
            if ($this->filterparams->origin !== '---') {
                // Filter by a single origin.
                $joins[] = "origin = :origin";
                $params['origin'] = $this->filterparams->origin;
            } else {
                // Filter by everything else.
                list($originsql, $originparams) = $DB->get_in_or_equal(array('cli', 'restore', 'ws', 'web'),
                    SQL_PARAMS_NAMED, 'origin', false);
                $joins[] = "origin " . $originsql;
                $params = array_merge($params, $originparams);
            }
        }

        if (!($this->filterparams->logreader instanceof logstore_legacy\log\store)) {
            // Filter out anonymous actions, this is N/A for legacy log because it never stores them.
            $joins[] = "anonymous = 0";
        }

        $selector = implode(' AND ', $joins);

        if (!$this->is_downloading()) {
            $this->total = $this->filterparams->logreader->get_events_select_count($selector, $params);
            $this->pagesize($pagesize, $this->total);
        } else {
            $this->pageable(false);
        }

        // Get the events.
        $this->rawdata = $this->filterparams->logreader->get_events_select_iterator($selector, $params,
        $this->filterparams->orderby, $this->get_page_start(), $this->get_page_size());

        // Set initial bars.
        if ($useinitialsbar && !$this->is_downloading()) {
            $this->initialbars($this->total > $pagesize);
        }
    }

    /**
     * Build hit-count buckets for a chart tab.
     *
     * Delegates to get_bucket_counts() with COUNT(*).
     *
     * @param string $duration hourly|daily|monthly
     * @return array label => hit count, chronological order
     */
    public function get_hits($duration = 'weekly') {
        return $this->get_bucket_counts($duration, 'COUNT(*)');
    }

    /**
     * Build active-user count buckets for a chart tab.
     *
     * Delegates to get_bucket_counts() with COUNT(DISTINCT userid).
     *
     * @param string $duration hourly|daily|monthly
     * @return array label => active user count, chronological order
     */
    public function get_active_users($duration = 'weekly') {
        return $this->get_bucket_counts($duration, 'COUNT(DISTINCT userid)');
    }

    /**
     * Common helper: run GROUP BY query and fill gaps.
     *
     * @param string $duration hourly|daily|monthly
     * @param string $countexpr SQL aggregation expression (e.g. COUNT(*) or COUNT(DISTINCT userid))
     * @return array label => count, chronological order
     */
    private function get_bucket_counts($duration, $countexpr) {
        global $DB;

        switch ($duration) {
            case 'hourly':
                $since = time() - DAYSECS;
                $sqlformat = '%Y-%m-%d %h %p';
                break;
            case 'daily':
                $since = time() - (30 * DAYSECS);
                $sqlformat = '%Y-%m-%e';
                break;
            case 'monthly':
            default:
                $since = time() - (365 * DAYSECS);
                $sqlformat = '%Y-%m';
                break;
        }

        // Use MySQL GROUP BY to aggregate counts directly.
        $sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(timecreated), '{$sqlformat}') AS label,
                       {$countexpr} AS value
                  FROM {logstore_standard_log}
                 WHERE timecreated > :since
              GROUP BY label
              ORDER BY MIN(timecreated) ASC";

        $records = $DB->get_records_sql($sql, ['since' => $since]);

        // Convert internal sortable labels back to display labels.
        $report = [];
        foreach ($records as $record) {
            $displaylabel = $this->format_display_label($record->label, $duration);
            $report[$displaylabel] = (int)$record->value;
        }

        // Fill in gaps (hours/days/months with no logs) with zero counts.
        $report = $this->fill_gaps($report, $duration, $since);

        return $report;
    }

    /**
     * Convert internal sortable label to human-readable display label.
     *
     * @param string $label Internal label (e.g. "2026-08-20 02 AM" or "2026-08-20" or "2026-08")
     * @param string $duration hourly|daily|monthly
     * @return string Display label (e.g. "02 AM" or "20th Aug 2026" or "Aug 2026")
     */
    private function format_display_label($label, $duration) {
        switch ($duration) {
            case 'hourly':
                // "2026-08-20 02 AM" → "02 AM"
                $parts = explode(' ', $label, 2);
                return $parts[1] ?? $label;
            case 'daily':
                // "2026-08-20" → "20th Aug 2026"
                $timestamp = strtotime($label);
                return date('jS M Y', $timestamp);
            case 'monthly':
            default:
                // "2026-08" → "Aug 2026"
                $timestamp = strtotime($label . '-01');
                return date('M Y', $timestamp);
        }
    }

    /**
     * Ensure every bucket exists (fill missing ones with zero).
     *
     * @param array $hitreport Existing hits.
     * @param string $duration hourly|daily|monthly
     * @param int $since Unix timestamp of start time.
     * @return array Complete hits with no gaps.
     */
    private function fill_gaps($hitreport, $duration, $since) {
        $now = time();
        $result = [];

        switch ($duration) {
            case 'hourly':
                // Generate all 24 hours for today (aligned to midnight).
                $midnight = strtotime('today', $now);
                for ($i = 0; $i < 24; $i++) {
                    $ts = $midnight + ($i * HOURSECS);
                    $label = date('h A', $ts);
                    $result[$label] = $hitreport[$label] ?? 0;
                }
                break;

            case 'daily':
                // Generate all days from $since to now.
                $start = strtotime(date('Y-m-d', $since));
                $end = strtotime(date('Y-m-d', $now));
                for ($ts = $start; $ts <= $end; $ts += DAYSECS) {
                    $displaylabel = date('jS M Y', $ts);
                    $result[$displaylabel] = $hitreport[$displaylabel] ?? 0;
                }
                break;

            case 'monthly':
            default:
                // Generate all months from $since to now.
                $start = strtotime(date('Y-m-01', $since));
                $end = strtotime(date('Y-m-01', $now));
                for ($ts = $start; $ts <= $end; $ts = strtotime('+1 month', $ts)) {
                    $displaylabel = date('M Y', $ts);
                    $result[$displaylabel] = $hitreport[$displaylabel] ?? 0;
                }
                break;
        }

        return $result;
    }

    /**
     * Generate chart data for all three time buckets.
     *
     * @param string $mode 'hits' for total log count, 'users' for unique active users
     * @return array Array keyed by duration (hourly/daily/monthly), each value is label => count
     */
    public function generate_graphdata($mode = 'hits') {
        $chartdata = [];
        $method = ($mode === 'users') ? 'get_active_users' : 'get_hits';
        foreach (['hourly', 'daily', 'monthly'] as $format) {
            $chartdata[$format] = $this->$method($format);
        }
        return $chartdata;
    }
}