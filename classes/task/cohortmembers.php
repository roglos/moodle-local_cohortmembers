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
 * A scheduled task for scripted database integrations - enrolling users to existing cohorts.
 *
 * @package    local_cohortmembers
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembers\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohortmembers extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_cohortmembers');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;
        require_once($CFG->dirroot.'/cohort/lib.php');

        /* Source data does not include timecreated - therefore selecting all users with
         * timecreated < 1 will give new users, if a timecreated stamp is added to the
         * record when processing. This saves processing every user every time. This is
         * specific to UoG where users are written to the Db wthout a timestamp.
         * More generic use would detect when this plugin last ran and process all users
         * created since then - on smaller sites could just allow all users to be processed
         * and accept any timing hit.
         */
        $allusers = $DB->get_records_select('user', "timecreated < 1 AND deleted = 0");

        /* MANAGED WHOLE SITE COHORTS.
         * =========================== */

        // Get the database id for each named cohort.
        $cohorts = array('mng_all_staff', 'mng_all_students', 'mng_all_users');
        // Loop through all site-wide managed cohorts.
        foreach ($cohorts as $result) {
            $cohort[$result] = $DB->get_record('cohort', array('idnumber' => $result), '*', MUST_EXIST);
        }

        // Loop through all users (NOTE: SELECT query above ensures this is new users only).
        foreach ($allusers as $user) {
            $record['userid'] = $userupdate['id'] = $user->id;

            // Update user details with timestamp - ensures not treated as new user next cron run.
            $userupdate['timecreated'] = time();
            $DB->update_record('user', $userupdate);

            // Add to all users if not already there.
            $record['cohortid'] = $cohort['mng_all_users']->id;
            if (!$DB->record_exists('cohort_members',
                array('cohortid' => $record['cohortid'], 'userid' => $record['userid']))) {
                cohort_add_member($record['cohortid'], $record['userid']);

            }

            // Determine if staff or student - based on email - change cohortid to one of
            // the sitewide managed cohorts
            // TODO: Future plan once sitewide staff role exists then can use that.
            switch (true) {
                case strstr($user->email, "@glos.ac.uk"):
                    $record['cohortid'] = $cohort['mng_all_staff']->id;
                    break;
                case strstr($user->email, "@connect.glos.ac.uk"):
                    $record['cohortid'] = $cohort['mng_all_students']->id;
                    break;
            }
            // If non-standard email do not set Staff/Student - we don't know which.

            // Add user to cohort_members table. Check its not trying to re-add to all users if no
            // valid email exists.
            if ($record['cohortid'] != $cohort['mng_all_users']->id) {
                // Make sure record doesn't already exist and add.
                if (!$DB->record_exists('cohort_members',
                    array('cohortid' => $record['cohortid'], 'userid' => $record['userid']))) {
                    cohort_add_member($record['cohortid'], $record['userid']);
                }

            }

        }

    }

}
