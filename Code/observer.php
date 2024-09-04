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
 * Local mitowebservices: Observer class - handles events for this plugin
 *
 * @package     local
 * @subpackage  local_mitowebservices
 * @author      Donald Barrett <donald.barrett@learningworks.co.nz>
 * @copyright   2016 LearningWorks Ltd
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_mitowebservices;

use context;
use auth_oauth2\linked_login;
use core\oauth2\issuer;
use core\oauth2\api;
use stdClass;
use Exception;
use moodle_url;

/**
 * Class observer. This is where we can listen to events and do things with them.
 *
 * @package local_mitowebservices
 * @copyright 2016, LearningWorks <admin@learningworks.co.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * When a user logs in we want to ensure that they are signed out of their mito openid.
     *
     * @param   \core\event\user_loggedin $event    The triggered event.
     * @return  bool                                Success/Failure.
     */
    public static function handle_user_loggedin(\core\event\user_loggedin $event) {
        global $DB;

        // Get some details about the event.
        $eventdata = $event->get_data();

        $user = $DB->get_record('user', array('id' => $eventdata['objectid']));
        $linkedlogin = linked_login::get_records(['userid' => $user->id]);
        if (!empty($linkedlogin)) {
            if ($user->auth == 'openid') {
                // There are issues. This user should change to oauth2.
                $user->auth = 'oauth2';
                user_update_user($user, false, false);
            }

            if (count($linkedlogin) > 1) {
                return true;
            }

            if (!isset($linkedlogin[0])) {
                return true;
            }

            // Update email address.
            $issuer = new issuer($linkedlogin[0]->get('issuerid'));
            $client = api::get_user_oauth_client($issuer, new moodle_url(''));
            $url = $client->get_issuer()->get_endpoint_url('userinfo');
            if (empty($url)) {
                return true;
            }
            $response = $client->get($url);
            if (!$response) {
                return true;
            }
            $userinfo = new stdClass();
            try {
                $userinfo = json_decode($response);
            } catch (Exception $ex) {
                return true;
            }
            $othermails = $userinfo->otherMails;
            if (isset($othermails) && is_array($othermails) && !empty($othermails[0]) && is_string($othermails[0])) {
                $user->email = $othermails[0];
                user_update_user($user, false, false);
            }
            return true;
        }

        // Log the user out of the SSO portal.
        if ($user->auth == 'openid') {
            // What is the logout url for the SSO.
            $ssologouturl       = get_config('local_mitowebservices', 'ssologouturl');

            // Where do we want to return to after the logout redirect?
            $ssologoutreturnurl = get_config('local_mitowebservices', 'ssologoutreturnurl');

            if (empty($user->idnumber)) {
                $ssologoutreturnurl = new \moodle_url('/login/logout.php', array('sesskey' => sesskey()));
                $ssologoutreturnurl = $ssologoutreturnurl->out();
                redirect(
                    "{$ssologouturl}?returnurl={$ssologoutreturnurl}",
                    get_string('loginnotauthorised', 'local_mitowebservices'),
                    3
                );
            }

            // If there is an SSO logout url then we can quickly redirect the user to log them out of their SSO provider silently.
            if ($ssologouturl) {
                redirect("{$ssologouturl}?returnurl={$ssologoutreturnurl}");
            }
        }

        // We are finished here.
        return true;
    }

    /**
     * When a user is deleted we need to clear their openid url from the auth plugins openid_urls table
     * and the local_mitowebservices_user table.
     *
     * @param \core\event\user_deleted $event   The triggererd event
     */
    public static function handle_user_deleted(\core\event\user_deleted $event) {
        global $DB;

        // Get some details about the event.
        $eventdata = $event->get_data();

        $deleteduserid = $eventdata['objectid'];

        // Clean up the users openid url from the openid_urls table.
        if ($DB->get_manager()->table_exists('openid_urls')) {
            $DB->delete_records('openid_urls', array( 'userid' => $deleteduserid ));
        }

        // Clean up the users mapping in the local_mitowebservices_user table.
        $DB->delete_records('local_mitowebservices_user', array( 'userid' => $deleteduserid ));
    }

    /**
     * Just like the user deleted event, we also need to clear any course mappings that we have in the plugin.
     *
     * @param \core\event\course_deleted $event
     */
    public static function handle_course_deleted(\core\event\course_deleted $event) {
        global $DB;

        // Get some details about the event.
        $eventdata = $event->get_data();

        // Clean up the course mapping in local_mitowebservices_course table.
        $deletedcourseid = $eventdata['objectid'];
        $DB->delete_records('local_mitowebservices_course', array( 'courseid' => $deletedcourseid ));
        $DB->delete_records('local_mitowebservices_prog_course', array( 'courseid' => $deletedcourseid ));

        // Todo: Do we need to log this somewhere?
    }

    /**
     * When a course module completion is updated, update the enrolment activity in ITOMIC via PATCH.
     *
     * @param \core\event\course_module_completion_updated $event
     */
    public static function patch_enrolment_activity(\core\event\course_module_completion_updated $event) {
        global $DB;

        // Get the event data to find the ids of the things that we need.
        $eventdata = $event->get_data();

        // First check that the eventdata has the keys that we require.
        $requiredkeys = array(
            'userid',       // The user that has had some course module completion updated.
            'courseid',     // The id of the course that the course module belongs to.
            'objectid',     // The objectid is the id in the course_modules_completion table.
            'contextid',    // The id of the course modules context. The instanceid will be the id of the course module.
            'timecreated'   // The timecreated value will be used for the lmscompletedate value passed to ITOMIC.
        );

        // Make a flag that will tell us if there are any keys missing.
        $hasrequiredkeys = true;

        // If there are any missing keys put them in an array so that we can notify something that these are missing.
        // There isn't really much that can be done because we aren't the ones who trigger the event but if there is an
        // update to any things that we don't manage we can catch them here.
        $missingkeys = array();

        foreach ($requiredkeys as $requiredkey) {
            if (!isset($eventdata[$requiredkey])) {
                $hasrequiredkeys = false;
                $missingkeys[] = $requiredkey;
            }
        }

        // If there are keys missing then we can't continue. Notify something or someone so that something can be done about it.
        if (!$hasrequiredkeys) {
            return;
        }

        // Get the userid from the eventdata.
        $userid = $eventdata['relateduserid'];

        // Get the courseid from the eventdata.
        $courseid = $eventdata['courseid'];

        // Get the id of the course modules completion data.
        $coursemodulescompletionid = $eventdata['objectid'];

        // Get the context id for the course module.
        $contextid = $eventdata['contextid'];

        // Prepare the ISO 8601 date for when this activity was complete.
        $activitytimecompleted = $eventdata['timecreated'];

        // If there is no value for timecreated set it to the current UNIX timestamp.
        if (empty($activitytimecompleted)) {
            $activitytimecompleted = time();
        }

        // Get the user for this completion event.
        if (!$user = $DB->get_record('user', array('id' => $userid))) {
            return;
        }

        // Check that the user and course have an idnumber.
        if (!isset($user->idnumber)) {
            return;
        }

        // Get the course for this completion event.
        if (!$course = $DB->get_record('course', array('id' => $courseid))) {
            return;
        }

        // Check that the course has an idnumber.
        if (!isset($course->idnumber)) {
            return;
        }

        // Get the course modules completion record.
        if (!$coursemodulescompletion = $DB->get_record('course_modules_completion', array('id' => $coursemodulescompletionid))) {
            return;
        }

        // Get the context of the course module by its contextid.
        if (!$context = context::instance_by_id($contextid)) {
            return;
        }

        // Get the course module from the context instanceid.
        if (!$coursemodule = $DB->get_record('course_modules', array('id' => $context->instanceid))) {
            return;
        }

        // Find out what the type of module is.
        if (!$module = $DB->get_record('modules', array('id' => $coursemodule->module))) {
            return;
        }

        // Get the actual mod.
        if (!$courseactivitymodule = $DB->get_record($module->name, array('id' => $coursemodule->instance))) {
            return;
        }

        // When a learner has failed then be sure to not pass this to ITOMIC.
        // if ($coursemodulescompletion->completionstate == COMPLETION_COMPLETE_FAIL) {
        //     return;
        // }

        // Check for any previous rpl completions
        $coursecompletioncriteriaparams = array(
            'criteriatype'      => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module'            => $module->name,
            'moduleinstance'    => $coursemodule->id,
            'course'            => $course->id
        );

        // Get the course completion criteria;
        if ($coursecompletioncriteria = $DB->get_record('course_completion_criteria', $coursecompletioncriteriaparams)) {
            // Get some params.
            $rplcompletionparams = array('criteriaid' => $coursecompletioncriteria->id, 'userid' => $userid, 'course' => $courseid);

            // Check for any prior completions i.e. from ITOMIC identified by an rpl value.
            if ($rplcompletion = $DB->get_record('course_completion_crit_compl', $rplcompletionparams)) {
                if (strlen($rplcompletion->rpl)) {
                    return;
                }
            }
        }

        // Ensure it's not a written/practical
        if ($courseactivitymodule->preferredbehaviour == 'deferredfeedback') {
            return;
        }

        // Queue this patch request.
        \local_mitowebservices\handlers\patch::queue(
            $user->idnumber, $course->idnumber, $courseactivitymodule->name, $activitytimecompleted, $module->name
        );

        return true;
    }

    /**
     * Handle a manually graded quiz so that the complete results get sent to ITOMIC.
     *
     * @param \mod_quiz\event\question_manually_graded $event
     */
    public static function handle_question_manually_graded(\mod_quiz\event\question_manually_graded $event) {
        global $DB, $USER, $CFG;

        require_once("{$CFG->libdir}/completionlib.php");

        // Get the event data.
        $eventdata = $event->get_data();

        // Get the attempt id from other.
        $attemptid = $eventdata['other']['attemptid'];

        // Get the attempt.
        $quizattempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (empty($quizattempt)) {
            return;
        }
        // If it's not finished then we don't want to do anything.
        if (empty($quizattempt->sumgrades) && $quizattempt->state == 'finished') {
            return;
        }

        // End early on preview
        if ($quizattempt->preview == 1) {
            return;
        }

        // Get the records we need
        $courseid = $eventdata['courseid'];
        $coursemoduleid = $eventdata['contextinstanceid'];
        $quizid = $quizattempt->quiz;
        $markeruserid = $eventdata['userid'];
        $learneruserid = $quizattempt->userid;

        // Get the learner record.
        if (!$learner = $DB->get_record('user', ['id' => $learneruserid])) {
            return;
        }

        // Get the marker record too, we don't mind too much if they don't exist. Would be kinda disappointing though.
        // Here we default to the learner id as the assessor just to have a value in the endpoint.
        $marker = $learner->idnumber;
        if ($DB->record_exists('user', ['id' => $markeruserid])) {
            $assessor = $DB->get_record('user', ['id' => $markeruserid]);

            // Is it an auto-marked or manually graded?
            $automarked = false;
            if ($assessor->id == $learner->id) {
                $automarked = true;
            }

            // Failsafe for manual - admin - accounts.
            if ($assessor->auth != 'manual') {
                $marker = $assessor->idnumber;
            }
        }   

        // Get the course.
        if (!$course = $DB->get_record('course', ['id' => $courseid])) {
            return;
        }

        // Get the course module.
        if (!$coursemodule = $DB->get_record('course_modules', ['id' => $coursemoduleid])) {
            return;
        }

        // Get the quiz.
        if (!$quiz = $DB->get_record('quiz', ['id' => $quizid])) {
            return;
        }

        $coursemodule = get_coursemodule_from_id('quiz', $coursemoduleid, $course->id);

        if (!($course && $quiz && $coursemodule && $quizattempt)) {
            return true;
        }

        // Update completion state.
        $completion = new \completion_info($course);
        if ($completion->is_enabled($coursemodule)) {
            if($quiz->completionattemptsexhausted || $quiz->completionpass) {
                $completion->update_state($coursemodule, COMPLETION_COMPLETE, $learneruserid);
            }
        }

        // Get the current unix timestamp
        $currentUnixTime = time();

        // Convert to datetime here if needed.
        // $currenttime = date('Y-m-d\TH:i:s\Z', $currentUnixTime);
        $currenttime = $currentUnixTime;
        
        // Check if complete
        $activitycomplete = "False";
        $completetime = NULL;
        $supervisorstatus = 876750007; // Failed
        if ($quiz->sumgrades == $quizattempt->sumgrades) {
            $activitycomplete = "True";
            $supervisorstatus = 876750004; // Passed
            $completetime = $currenttime;
        }
        
        // -- Automatic Override -- //
        $cmc = $DB->record_exists('course_modules_completion', ['coursemoduleid' => $coursemodule->id, 'userid' => $quizattempt->userid, 'completionstate' => 1]);
        if (isset($quizattempt->sumgrades) && $activitycomplete == "False" && $cmc == false && $quiz->preferredbehaviour == 'deferredfeedback' && $quiz->sumgrades > $quizattempt->sumgrades) {
            // If the record already exists
            if ($qover = $DB->get_record('quiz_overrides', ['quiz' => $quizattempt->quiz, 'userid' => $quizattempt->userid])) {
                if ($qover->attempts <= $quizattempt->attempt) {
                    $qover->attempts = $quizattempt->attempt +1;
                    $DB->update_record('quiz_overrides', $qover);
                }
            }
            // If the record needs to be created
            else {
                $overridemap = array(
                    'quiz'          => $quizattempt->quiz,
                    'groupid'       => NULL,
                    'userid'        => $quizattempt->userid,
                    'timeopen'      => NULL,
                    'timeclose'     => NULL,
                    'timelimit'     => NULL,
                    'attempts'      => $quizattempt->attempt + 1,
                    'password'      => NULL
                );
                $DB->insert_record('quiz_overrides', $overridemap);
            }
        }
        // -- Assessor Queues --
        // Check for any prior attempts
        if ($quizattempt->attempt > 1) {
            $priornum = $quizattempt->attempt - 1;
            if ($priorattemptobj = $DB->get_record('quiz_attempts', ['quiz' => $quizattempt->quiz, 'userid' => $quizattempt->userid, 'attempt' => $priornum, 'state' => 'finished'])) {
                $priorattempt = $priorattemptobj->id;
            }
            else {
                $priorattempt = null;
            }
        }
        else {
            $priorattempt = null;
        }

        // If the record doesn't exist, create it if the user has the assessor role
        if (!$queue = $DB->get_record('local_assessorqueue', ['attemptid' => $quizattempt->id])) {

            // The user needs to be an assessor or admins/ta's can trigger this too
            if ($DB->record_exists('role_assignments', ['roleid' => 11, 'userid' => $USER->id])) {

                // Ensure the quiz has at least one graded answer before assigning an assessor - avoids the situation where an assessor jumps in and out
                if ($qattempts = $DB->get_records('question_attempts', ['questionusageid' => $quizattempt->uniqueid])) {
                    foreach ($qattempts as $attempt) {
                        if ($DB->record_exists('question_attempt_steps', ['questionattemptid' => $attempt->id, 'state' => 'mangrright'])) {
                            $queuemap = array (
                                'userid'            => $USER->id,
                                'attemptid'         => $quizattempt->id,
                                'attemptidprior'    => $priorattempt,
                                'override'          => $quizattempt->attempt,
                                'state'             => 0,
                                'timestart'         => $currenttime,
                                'timefinish'        => null
                            );
                            $DB->insert_record('local_assessorqueue', $queuemap);
                            break;
                        }
                        else if ($DB->record_exists('question_attempt_steps', ['questionattemptid' => $attempt->id, 'state' => 'mangrwrong'])) {
                            $queuemap = array (
                                'userid'            => $USER->id,
                                'attemptid'         => $quizattempt->id,
                                'attemptidprior'    => $priorattempt,
                                'override'          => $quizattempt->attempt,
                                'state'             => 0,
                                'timestart'         => $currenttime,
                                'timefinish'        => null
                            );
                            $DB->insert_record('local_assessorqueue', $queuemap);
                            break;
                        }                  
                    }
                }
            }
        }
        // If it does exist and the quiz has been marked, update it regardless of user
        else if ($queue && isset($quizattempt->sumgrades) && $queue->state == 0) {
            $queue->userid = $USER->id;
            $queue->state = 1;
            $queue->timefinish = $currenttime;
            $DB->update_record('local_assessorqueue', $queue);
        }
        // -- Assessor Queues End --

        // An updated written assessment should have the same completion in CRM but under a different name. 
        // Make sure it comes through under a universal naming convention.
        if (strpos($quiz->name, 'Written assessment') !== false) {
            $quiz->name = 'Written assessment';
        }
        if (strpos($quiz->name, 'Written Assessment') !== false) {
            $quiz->name = 'Written Assessment';
        }

        // Get the assessor
        if ($assessorqueue = $DB->get_record('local_assessorqueue', ['attemptid' => $quizattempt->id])) {
            if ($assessorobj = $DB->get_record('user', ['id' => $assessorqueue->userid])){
                $assessorid = $assessorobj->idnumber;
            }
            else {
                $assessorid = NULL;
            }
        }
        else {
            $assessorid = NULL;
        }

        // Avoid inactive user's programme/package completion issues in CRM
        if ($learner->suspended == 1) {
            return;
        }

        // Finally, send the PATCH through
        $payload = $fields = [];
        $fields['status']               = "Active";
        $fields['person.id']            = $learner->idnumber;
        $fields['person.username']      = $learner->username;
        $fields['person.email']         = $learner->email;
        $fields['course.id']            = $course->idnumber;
        $fields['course.code']          = $course->shortname;
        $fields['activity.name']        = $quiz->name;
        $fields['activity.type']        = "prefix_quiz";
        $fields['modified']             = $currenttime;
        $fields['lmscomplete']          = $activitycomplete;
        $fields['lmscompletedate']      = $completetime;
        $fields['supervisorstatus']     = $supervisorstatus;
        if ($assessorid !== NULL) {
            $fields['assessor']             = $assessorid;
        }

        
        $payload[] = $fields;
        
        $mitoclient = new \local_mitowebservices\mito_client();
        
        $patchurl = "completeactivities/";
        $id = "";
        try {
            $response = $mitoclient->send_request($patchurl,$payload);
        } 
        catch (Exception $e) {
        }

        if ($response == false && (!$DB->record_exists('local_mitowebservices_backup_patch', ['userid' => $learner->id, 'courseid' => $course->id, 'quizname' => $quiz->name, 'attemptid' => $quizattempt->id, 'type' => 'completeactivities']))) {
            $newbackup = array(
                'userid'            => $learner->id,
                'useridnumber'      => $learner->idnumber,
                'courseid'          => $course->id,
                'courseidnumber'    => $course->idnumber,
                'quizname'          => $quiz->name,
                'timecomplete'      => $quizattempt->timemodified,
                'attemptid'         => $quizattempt->id,
                'status'            => 0,
                'timeadded'         => $currenttime,
                'type'              => 'completeactivities'
            );
            $DB->insert_record('local_mitowebservices_backup_patch', $newbackup);
        }
    }
    
    public static function handle_quiz_attempt_update($event){

        // Now we can do some stuff
        global $DB, $USER;
        $currenttime = time();
        
        // Get the event data.
        $eventdata = $event->get_data();
        
        // Get the attempt id from other.
        $attemptid = $eventdata['objectid'];

        //
        // CARRY OVER GRADES ON RESITS
        //
        // Updates all prior grades on deferred feedback quizzes to the current attempt
        function update_deferredfeedback($attemptid) {

            // We need this, very important, if you delete this you suck.
            global $DB, $USER;

            // Get the attempt
            $quizattempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);

            // Get the prior attempt id
            $priorattemptnum = $quizattempt->attempt - 1;
            // Get the prior attempt 
            if (!$priorattempt = $DB->get_record('quiz_attempts', ['quiz' => $quizattempt->quiz, 'userid' => $quizattempt->userid, 'attempt' => $priorattemptnum])) {
                return;
            }
            // Get the prior question attempt
            $prioranswers = $DB->get_records('question_attempts', ['questionusageid' => $priorattempt->uniqueid]);

            // Make some templates before our loop
            $stepmap = array (
                'questionattemptid'         => '',
                'sequencenumber'            => '',
                'state'                     => 'mangrright',
                'fraction'                  => 1.0000000,
                'timecreated'               => time(),
                'userid'                    => $quizattempt->userid
            );
            $stepdatamap = array (
                'attemptstepid'             => '',
                'name'                      => '-comment',
                'value'                     => ''
            );

            // Loop through all answers to get the steps and step data
            foreach ($prioranswers as $prioranswer) {

                // If it's not a manually graded question move on
                if ($prioranswer->behaviour != 'manualgraded') {
                    continue;
                }
                // Assume nothing, question everything
                $commentset = false;

                //
                // PRIOR 
                //

                // Get the prior attempt step, we need the latest record only
                $querystep = "
                SELECT *
                FROM {question_attempt_steps}
                WHERE
                questionattemptid = $prioranswer->id
                AND state = 'mangrright'
                ORDER BY timecreated DESC
                LIMIT 1
                ";
                // If there's nothing in there, move on
                if (!$priorstep = $DB->get_records_sql($querystep)) {
                    continue;
                }
                // Open the array, we cannot assume the object's name so take the first (and only)
                $firstElement = reset($priorstep);
                $priorstepid = $firstElement->id;
                $state = $firstElement->state;

                // Get the prior attempt step, we need the latest record only
                $querystepdata = "
                SELECT *
                FROM {question_attempt_step_data}
                WHERE
                attemptstepid = $priorstepid
                AND name = '-comment'
                ORDER BY id DESC
                LIMIT 1
                ";
                // If there's nothing in there, move on
                if (!$priorstepdata = $DB->get_records_sql($querystepdata)) {
                    $commentset = true;
                }
                // Open the array, we cannot assume the object's name so take the first (and only)
                $firstElement = reset($priorstepdata);
                $priorstepdataid = $firstElement->id;

                // Get the prior attempt step data, we only want comments
                if ($priorstepdata = $DB->get_record('question_attempt_step_data', ['id' => $priorstepdataid])) {
                    $commentset = true;
                }

                //
                // CURRENT 
                //

                // Get the current attempt question attempt, we don't want finish this iteration if there isn't one
                if (!$questionattempt = $DB->get_record('question_attempts', ['questionusageid' => $quizattempt->uniqueid, 'questionid' => $prioranswer->questionid])) {
                    continue;
                }
                // Get the current latest attempt step, we don't want finish this iteration if there isn't one
                if (!$step = $DB->get_record('question_attempt_steps', ['questionattemptid' => $questionattempt->id, 'state' => 'needsgrading'])) {
                    continue;
                }
                
                //
                // GRADES
                //

                // Add it if it doesn't exist
                if (!$DB->record_exists('question_attempt_steps', ['questionattemptid' => $questionattempt->id, 'state' => 'mangrright'])) {
                    
                    // Make some templates before our loop
                    $stepmap = array (
                        'questionattemptid'         => $step->questionattemptid,
                        'sequencenumber'            => $step->sequencenumber + 1,
                        'state'                     => 'mangrright',
                        'fraction'                  => 1.0000000,
                        'timecreated'               => time(),
                        'userid'                    => $quizattempt->userid
                    );

                    $DB->insert_record('question_attempt_steps', $stepmap);
                }

                //
                // COMMENTS
                //

                // If there was a comment, add it to the new record
                if ($commentset == true) {

                    // Generate the new record
                    $stepdatamap = array (
                        'attemptstepid'             => $step->id,
                        'name'                      => '-comment',
                        'value'                     => $priorstepdata->value
                    );

                    // Insert the new comment record
                    $DB->insert_record('question_attempt_step_data', $stepdatamap);

                    // Insert the new commentformat record
                    $stepdatamap['value'] = 1;
                    $stepdatamap['name'] = '-commentformat';
                    $DB->insert_record('question_attempt_step_data', $stepdatamap);

                    // Insert the new mark record
                    $stepdatamap['name'] = '-mark';
                    $DB->insert_record('question_attempt_step_data', $stepdatamap);

                    // Insert the new mark record
                    $stepdatamap['name'] = '-maxmark';
                    $DB->insert_record('question_attempt_step_data', $stepdatamap);
                }
            }
            return;
        }
        
        // Get the attempt, run any conditions
        if (!$quizattempt = $DB->get_record('quiz_attempts', ['id' => $attemptid])) {
            return;
        }

        // If it's in progress, end.
        if ($quizattempt->state == "inprogress") {
            return;
        }
        // If it's a preview, end.
        if ($quizattempt->preview == 1) {
            return;
        }

        // -- Assessor Queues --
        // Check for any prior attempts
        if ($quizattempt->attempt > 1) {
            $priornum = $quizattempt->attempt - 1;
            if ($priorattemptobj = $DB->get_record('quiz_attempts', ['quiz' => $quizattempt->quiz, 'userid' => $quizattempt->userid, 'attempt' => $priornum, 'state' => 'finished'])) {
                $priorattempt = $priorattemptobj->id;
            }
            else {
                $priorattempt = null;
            }
        }
        else {
            $priorattempt = null;
        }
        // If the record doesn't exist, create it if the user has the assessor role
        $queue = $DB->get_record('local_assessorqueue', ['attemptid' => $quizattempt->id]);
        if (!$queue) {

            // The user needs to be an assessor or admins/ta's can trigger this too
            if ($DB->record_exists('role_assignments', ['roleid' => 11, 'userid' => $USER->id])) {
                $loggeduser = $USER->id;
            }
            else {
                $loggeduser = NULL;
            }

            // Ensure the quiz has at least one graded answer before assigning an assessor - avoids the situation where an assessor jumps in and out
            if ($qattempts = $DB->get_records('question_attempts', ['questionusageid' => $quizattempt->uniqueid])) {
                foreach ($qattempts as $attempt) {
                    if ($DB->record_exists('question_attempt_steps', ['questionattemptid' => $attempt->id, 'state' => 'mangrright'])) {
                        $queuemap = array (
                            'userid'            => $loggeduser,
                            'attemptid'         => $quizattempt->id,
                            'attemptidprior'    => $priorattempt,
                            'override'          => $quizattempt->attempt,
                            'state'             => 0,
                            'timestart'         => $currenttime,
                            'timefinish'        => null
                        );
                        $DB->insert_record('local_assessorqueue', $queuemap);
                        break;
                    }
                    else if ($DB->record_exists('question_attempt_steps', ['questionattemptid' => $attempt->id, 'state' => 'mangrwrong'])) {
                        $queuemap = array (
                            'userid'            => $loggeduser,
                            'attemptid'         => $quizattempt->id,
                            'attemptidprior'    => $priorattempt,
                            'override'          => $quizattempt->attempt,
                            'state'             => 0,
                            'timestart'         => $currenttime,
                            'timefinish'        => null
                        );
                        $DB->insert_record('local_assessorqueue', $queuemap);
                        break;
                    }                  
                }
            }
        }
        // If it does exist and the quiz has been marked, update it regardless of user
        else if ($queue && isset($quizattempt->sumgrades) && $queue->state == 0) {
            $queue->userid = $USER->id;
            $queue->state = 1;
            $queue->timefinish = $currenttime;
            $DB->update_record('local_assessorqueue', $queue);
        }
        // Run an update on the record if values do not match
        else if ($queue && !isset($quizattempt->sumgrades) || $queue->status == 0) {
            if ($priorattempt && $queue->attemptidprior != $priorattempt) {
                $queue->attemptidprior = $priorattempt;
                $DB->update_record('local_assessorqueue', $queue);
            }
        }
        // -- Assessor Queues End --

        // Get the user's moodle profile
        $mdluser = $DB->get_record('user', ['id' => $quizattempt->userid]);
        // Avoid patching potential inactive user/programme/package completion errors
        if ($mdluser->suspended == 1) {
            return;
        }

        // Build the user object
        $person = array(
            'idnumber'      => $mdluser->idnumber,
            'username'      => $mdluser->username,
            'firstname'     => $mdluser->firstname,
            'lastname'      => $mdluser->lastname,
            'email'         => $mdluser->email
        );
        
        // Get quiz
        $quiz = $DB->get_record('quiz', ['id' => $quizattempt->quiz]);

        // Build the quiz object
        $quizmap = array(
            'id'        => $quiz->id,
            'name'      => $quiz->name,
            'sumgrades' => $quiz->sumgrades,
            'course'    => $quiz->course
        );

        // Get course
        $course = $DB->get_record('course', ['id' => $quiz->course]);

        // Build the course object
        $coursemap = array(
            'id'            => $course->id,
            'idnumber'      => $course->idnumber,
            'shortname'     => $course->shortname,
            'fullname'      => $course->fullname,
        );
        
        // Get the assessor
        if ($assessorqueue = $DB->get_record('local_assessorqueue', ['attemptid' => $quizattempt->id])) {
            if ($assessorobj = $DB->get_record('user', ['id' => $assessorqueue->userid])) {
                $assessorid = $assessorobj->idnumber;
            }
            else {
                $assessorid = null;
            }
        }
        else {
            $assessorid = null;
        }

        // Different written assessment versions have the same completion regardless
        // Adjust the value to ensure CRM accepts the name
        if (strpos($quiz->name, 'Written assessment') !== false) {
            $oldname = $quiz->name;
            $quiz->name = 'Written assessment';
        }
        else if (strpos($quiz->name, 'Written Assessment') !== false) {
            $oldname = $quiz->name;
            $quiz->name = 'Written Assessment';
        }
        
        $send = $data = [];
        $data['id']                 = $attemptid;
        $data['user']               = $person;
        $data['course']             = $coursemap;
        $data['quiz']               = $quizmap;
        $data['attempt']            = $quizattempt->attempt;
        $data['state']              = $quizattempt->state;
        $data['timestart']          = $quizattempt->timestart;
        $data['timefinish']         = $quizattempt->timefinish;
        $data['timemodified']       = $quizattempt->timemodified;
        $data['sumgrades']          = $quizattempt->sumgrades;
        // $data['deleted']              = $isdeleted;
        // $data['supervisorstatus']     = $supervisorstatus;
        
        $send[] = $data;
        
        $mitoclient = new \local_mitowebservices\mito_client();
        
        $patchurl = "quizattempts/";
        $id = "";
        try {
            $response = $mitoclient->send_request($patchurl,$send);
        } 
        catch (Exception $e) {
        }

        if (!isset($response) && (!$DB->record_exists('local_mitowebservices_backup_patch', ['userid' => $mdluser->id, 'courseid' => $course->id, 'quizname' => $oldname, 'attemptid' => $quizattempt->id, 'type' => 'quizattempts']))) {
            $newbackup = array(
                'userid'            => $mdluser->id,
                'useridnumber'      => $mdluser->idnumber,
                'courseid'          => $course->id,
                'courseidnumber'    => $course->idnumber,
                'quizname'          => $quiz->name,
                'timecomplete'      => $quizattempt->timemodified,
                'attemptid'         => $quizattempt->id,
                'status'            => 0,
                'timeadded'         => $currenttime,
                'type'              => 'quizattempts'
            );
            $DB->insert_record('local_mitowebservices_backup_patch', $newbackup);
        }
        
        // If it's automarked and complete, send it to completeactivities and mark it as complete
        if ($quiz->preferredbehaviour != 'deferredfeedback' && isset($quizattempt->sumgrades) && $quiz->sumgrades == $quizattempt->sumgrades) {

            // We know they've passed by their sumgrades
            $activitycomplete = "True";
            $supervisorstatus = 876750004;
            
            $payload = $fields = [];
            $fields['status']               = "Active";
            $fields['person.id']            = $mdluser->idnumber;
            $fields['person.username']      = $mdluser->username;
            $fields['person.email']         = $mdluser->email;
            $fields['course.id']            = $course->idnumber;
            $fields['course.code']          = $course->shortname;
            $fields['activity.name']        = $quiz->name;
            $fields['activity.type']        = "prefix_quiz";
            $fields['modified']             = $currenttime;
            $fields['lmscomplete']          = $activitycomplete;
            $fields['lmscompletedate']      = $currenttime;
            $fields['supervisorstatus']     = $supervisorstatus;
            if ($assessorid !== null) {
                $fields['assessor']             = $assessorid;
            }

            $payload[] = $fields;
            
            $patchurl = "completeactivities/";
            $id = "";
            try {
                $response = $mitoclient->send_request($patchurl,$payload);
            } 
            catch (Exception $e) {
            }

            if (!isset($response) && !($DB->record_exists('local_mitowebservices_backup_patch', ['userid' => $mdluser->id, 'courseid' => $course->id, 'quizname' => $quiz->name, 'attemptid' => $quizattempt->id, 'type' => 'completeactivities']))) {
                $newbackup = array(
                    'userid'            => $mdluser->id,
                    'useridnumber'      => $mdluser->idnumber,
                    'courseid'          => $course->id,
                    'courseidnumber'    => $course->idnumber,
                    'quizname'          => $quiz->name,
                    'timecomplete'      => $quizattempt->timemodified,
                    'attemptid'         => $quizattempt->id,
                    'status'            => 0,
                    'timeadded'         => $currenttime,
                    'type'              => 'completeactivities'
                );
                $DB->insert_record('local_mitowebservices_backup_patch', $newbackup);
            }
        }

        // Update the grades if needed
        if ($quizattempt->attempt > 1 && $quiz->preferredbehaviour == 'deferredfeedback') {
            update_deferredfeedback($quizattempt->id);
        }
    }

    /**
     * Attempt at realtime completion using new endpoint.
     * Josh Steinmetz 2023-04-12 16:15
     */
    
    public static function handle_enrolmentactivity_completion_update($event){

        // Now we can do some stuff
        global $DB;

        $currenttime = time();
        
        // Get the event data.
        $eventdata = $event->get_data();

        // Store the id
        $attemptid = $eventdata['objectid'];
        
        // Get the attempt.
        $quizattempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);

        // End on previews
        if ($quizattempt->preview == 1) {
            return;
        }

        // Get the user's moodle profile
        $mdluser = $DB->get_record('user', ['id' => $quizattempt->userid]);
        // Avoid patching potential inactive user errors
        if ($mdluser->suspended == 1) {
            return;
        }

        // Build the user object
        $person = array(
            'idnumber'      => $mdluser->idnumber,
            'username'      => $mdluser->username,
            'firstname'     => $mdluser->firstname,
            'lastname'      => $mdluser->lastname,
            'email'         => $mdluser->email
        );
        
        // Get quiz
        $quiz = $DB->get_record('quiz', ['id' => $quizattempt->quiz]);

        // Remove 'updated 2020' strings to map to CRM - CRM package completion should occur regardless of version
        if (strpos($quiz->name, 'Written assessment') !== false) {
            $oldname = $quiz->name;
            $quiz->name = 'Written assessment';
        }
        else if (strpos($quiz->name, 'Written Assessment') !== false) {
            $oldname = $quiz->name;
            $quiz->name = 'Written Assessment';
        }

        // Build the quiz object
        $quizmap = array(
            'id'        => $quiz->id,
            'name'      => $quiz->name,
            'sumgrades' => $quiz->sumgrades,
            'course'    => $quiz->course
        );

        // Get course
        $course = $DB->get_record('course', ['id' => $quiz->course]);

        // Build the course object
        $coursemap = array(
            'id'            => $course->id,
            'idnumber'      => $course->idnumber,
            'shortname'     => $course->shortname,
            'fullname'      => $course->fullname,
        );
        
        $send = $data = [];
        $data['id']             = $attemptid;
        $data['user']           = $person;
        $data['course']         = $coursemap;
        $data['quiz']           = $quizmap;
        $data['attempt']        = $quizattempt->attempt;
        $data['state']          = $quizattempt->state;
        $data['timestart']      = $quizattempt->timestart;
        $data['timefinish']     = $quizattempt->timefinish;
        $data['timemodified']   = $quizattempt->timemodified;
        $data['sumgrades']      = $quizattempt->sumgrades;
        
        $send[] = $data;
        
        $mitoclient = new \local_mitowebservices\mito_client();
        
        $patchurl = "quizattempts/";
        $id = "";
        try {
            /*
             *This is sending to completeactivity replacing with a basic send request Jsteinmetz  2023-04-12 12:14pm
             *
             * $response = $mitoclient->patch_enrolmentactivity($patchurl,$send);
             */
             $response = $mitoclient->send_request($patchurl,$send);
        } 
        catch (Exception $e) {
        }

        if (!isset($response) && (!$DB->record_exists('local_mitowebservices_backup_patch', ['userid' => $mdluser->id, 'courseid' => $course->id, 'quizname' => $quiz->name, 'attemptid' => $quizattempt->id, 'type' => 'quizattempts']))) {
            $newbackup = array(
                'userid'            => $mdluser->id,
                'useridnumber'      => $mdluser->idnumber,
                'courseid'          => $course->id,
                'courseidnumber'    => $course->idnumber,
                'quizname'          => $quiz->name,
                'timecomplete'      => $quizattempt->timemodified,
                'attemptid'         => $quizattempt->id,
                'status'            => 0,
                'timeadded'         => $currenttime,
                'type'              => 'quizattempts'
            );
            $DB->insert_record('local_mitowebservices_backup_patch', $newbackup);
        }
    }
}