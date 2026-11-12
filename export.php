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
 * Export daily active users statistics as a CSV file.
 *
 * Headers: date, total users, daily logged-in users, daily login rate.
 * Dates run from the last 31 days ending at yesterday.
 *
 * @package    block_logreport
 * @copyright  2018 onwards Naveen kumar(naveen@eabyas.in)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
require_capability('report/log:view', $context);

global $DB;

// Only active users mode is supported.
$mode = optional_param('mode', 'users', PARAM_ALPHA);
if ($mode !== 'users') {
    throw new moodle_exception('error');
}

// Supported export granularity: daily / monthly.
$duration = optional_param('duration', 'daily', PARAM_ALPHA);
if (!in_array($duration, ['daily', 'monthly'], true)) {
    $duration = 'daily';
}

$today = strtotime(date('Y-m-d'));

switch ($duration) {
    case 'monthly':
        // From the current month back up to 24 months (24 rows ending at the current month).
        $end = strtotime(date('Y-m-01'));   // First day of the current month (exclusive).
        $since = strtotime('-23 month', $end);
        $sqlformat = '%Y-%m';
        $periodfunc = function ($ts) {
            return date('Y-m', $ts);
        };
        $steppfunc = function ($ts) {
            return strtotime('+1 month', $ts);
        };
        $loginuserstr = 'export_monthly_login_users';
        $filename = 'logreport_monthly_active_users.csv';
        break;

    case 'daily':
    default:
        // From yesterday back up to 100 days (100 rows ending at yesterday).
        $end = $today;                    // Midnight today (exclusive upper boundary).
        $since = $today - (100 * DAYSECS); // Midnight of the day 100 days ago.
        $sqlformat = '%Y-%m-%d';
        $periodfunc = function ($ts) {
            return date('Y-m-d', $ts);
        };
        $steppfunc = function ($ts) {
            return $ts + DAYSECS;
        };
        $loginuserstr = 'export_daily_login_users';
        $filename = 'logreport_daily_active_users.csv';
        break;
}

// Distinct active (logged-in) user counts within the range.
$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(timecreated), '{$sqlformat}') AS period,
               COUNT(DISTINCT userid) AS cnt
          FROM {logstore_standard_log}
         WHERE timecreated >= :since AND timecreated < :end
      GROUP BY period";
$records = $DB->get_records_sql($sql, ['since' => $since, 'end' => $end]);

// Total users currently in the system (non-deleted).
$totalusers = $DB->count_records('user', ['deleted' => 0]);

// Build the CSV content in a temp stream.
$handle = fopen('php://temp', 'w');
fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel displays Chinese characters correctly.

$headers = [
    get_string('export_date', 'block_logreport'),
    get_string('export_total_users', 'block_logreport'),
    get_string($loginuserstr, 'block_logreport'),
    get_string('export_login_rate', 'block_logreport'),
];
fputcsv($handle, $headers);

// Rows run chronologically ending at the most recent period (yesterday / current month).
for ($ts = $since; $ts < $end; $ts = $steppfunc($ts)) {
    $period = $periodfunc($ts);
    $logincount = isset($records[$period]) ? (int)$records[$period]->cnt : 0;
    $rate = $totalusers > 0 ? number_format($logincount / $totalusers * 100, 2) . '%' : '0%';
    fputcsv($handle, [$period, $totalusers, $logincount, $rate]);
}

rewind($handle);
$csv = stream_get_contents($handle);
fclose($handle);

// Send the file for download.
send_file($csv, $filename, 0, 0, true, true, 'text/csv');
exit;