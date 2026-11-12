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

// Build the last 31 days ending at "yesterday".
$today = strtotime(date('Y-m-d'));       // Midnight today.
$end = $today;                           // Exclusive upper boundary.
$since = $today - (31 * DAYSECS);        // Midnight of the day 31 days ago.

// Daily distinct active (logged-in) user counts within the range.
$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(timecreated), '%Y-%m-%d') AS day,
               COUNT(DISTINCT userid) AS cnt
          FROM {logstore_standard_log}
         WHERE timecreated >= :since AND timecreated < :end
      GROUP BY day";
$records = $DB->get_records_sql($sql, ['since' => $since, 'end' => $end]);

// Total users currently in the system (non-deleted).
$totalusers = $DB->count_records('user', ['deleted' => 0]);

// Build the CSV content in a temp stream.
$handle = fopen('php://temp', 'w');
fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel displays Chinese characters correctly.

$headers = [
    get_string('export_date', 'block_logreport'),
    get_string('export_total_users', 'block_logreport'),
    get_string('export_daily_login_users', 'block_logreport'),
    get_string('export_login_rate', 'block_logreport'),
];
fputcsv($handle, $headers);

// Rows run chronologically from 31 days ago up to yesterday (inclusive).
for ($ts = $since; $ts < $end; $ts += DAYSECS) {
    $day = date('Y-m-d', $ts);
    $logincount = isset($records[$day]) ? (int)$records[$day]->cnt : 0;
    $rate = $totalusers > 0 ? number_format($logincount / $totalusers * 100, 2) . '%' : '0%';
    fputcsv($handle, [$day, $totalusers, $logincount, $rate]);
}

rewind($handle);
$csv = stream_get_contents($handle);
fclose($handle);

// Send the file for download.
send_file($csv, 'logreport_daily_active_users.csv', 0, 0, true, true, 'text/csv');
exit;