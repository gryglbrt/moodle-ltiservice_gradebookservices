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
 * This file contains a class definition for the LISResult resource
 *
 * @package    ltiservice_gradebookservices
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @author     Dirk Singels, Diego del Blanco, Claude Vervoort
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace ltiservice_gradebookservices\local\resource;

use ltiservice_gradebookservices\local\service\gradebookservices;

defined('MOODLE_INTERNAL') || die();

/**
 * A resource implementing LISResult.
 *
 * @package    ltiservice_gradebookservices
 * @since      Moodle 3.0
 * @copyright  2017 Cengage Learning http://www.cengage.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result extends \mod_lti\local\ltiservice\resource_base {

    /**
     * Class constructor.
     *
     * @param ltiservice_gradebookservices\local\service\gradebookservices $service Service instance
     */
    public function __construct($service) {

        parent::__construct($service);
        $this->id = 'Result.item';
        $this->template = '/{context_id}/lineitems/{item_id}/results/{result_id}/result';
        $this->variables[] = 'Result.url';
        $this->formats[] = 'application/vnd.ims.lis.v2.result+json';
        $this->methods[] = 'GET';

    }

    /**
     * Execute the request for this resource.
     *
     * @param mod_lti\local\ltiservice\response $response  Response object for this request.
     */
    public function execute($response) {
        global $CFG, $DB;

        $params = $this->parse_template();
        $contextid = $params['context_id'];
        $itemid = $params['item_id'];
        $resultid = $params['result_id'];
        $isget = $response->get_request_method() === 'GET';
        if ($isget) {
            $contenttype = $response->get_accept();
        } else {
            throw new \Exception(null, 405);
        }

        try {
            if (!$this->check_tool_proxy(null, $response->get_request_data())) {
                throw new \Exception(null, 401);
            }
            if (empty($contextid) || (!empty($contenttype) && !in_array($contenttype, $this->formats))) {
                throw new \Exception(null, 400);
            }
            if ($DB->get_record('course', array('id' => $contextid)) === false) {
                throw new \Exception(null, 404);
            }
            if ($DB->get_record('grade_items', array('id' => $itemid)) === false) {
                throw new \Exception(null, 404);
            }
            if (($item = $this->get_service()->get_lineitem($contextid, $itemid)) === false) {
                throw new \Exception(null, 403);
            }
            if (isset($item->iteminstance) && (!gradebookservices::check_lti_id($item->iteminstance, $item->courseid,
                    $this->get_service()->get_tool_proxy()->id))) {
                        throw new \Exception(null, 403);
            }
            require_once($CFG->libdir.'/gradelib.php');

            $response->set_content_type($this->formats[0]);
            $grade = \grade_grade::fetch(array('itemid' => $itemid, 'userid' => $resultid));
            if (!$grade) {
                // If there is not grade but the user is allowed in the site
                // create an empty answer.
                if (gradebookservices::is_user_gradable_in_course($contextid, $resultid)) {
                    $lineitems = new lineitems($this->get_service());
                    $endpoint = $lineitems->get_endpoint();
                    $id = "{$endpoint}/{$itemid}/results/{$resultid}/result";
                    $result = new \stdClass();
                    $result->id = $id;
                    $result->userId = $resultid;
                    $result->scoreOf = $endpoint;
                    $json = json_encode($result, JSON_UNESCAPED_SLASHES);
                    $response->set_body($json);
                } else {
                    throw new \Exception(null, 404);
                }
            } else {
                $json = $this->get_request_json($grade, $resultid);
                $response->set_body($json);
            }

        } catch (\Exception $e) {
            $response->set_code($e->getCode());
        }

    }

    /**
     * Generate the JSON for a GET request.
     *
     * @param object $grade       Grade instance
     * @param object $resultid    The id of the result
     *
     * return string
     */
    private function get_request_json($grade, $resultid) {

        $lineitem = new lineitem($this->get_service());
        if (empty($grade->finalgrade)) {
            $grade->userid = $resultid;
            $json = gradebookservices::result_to_json($grade, $lineitem->get_endpoint());
        } else {
            if (empty($grade->timemodified)) {
                throw new \Exception(null, 400);
            }
            $json = gradebookservices::result_to_json($grade, $lineitem->get_endpoint());
        }
        return $json;

    }

    /**
     * Parse a value for custom parameter substitution variables.
     *
     * @param string $value String to be parsed
     *
     * @return string
     */
    public function parse_value($value) {
        global $COURSE, $USER, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $this->params['context_id'] = $COURSE->id;
        $id = optional_param('id', 0, PARAM_INT); // Course Module ID.
        try {
            if (!empty($id)) {
                $cm = get_coursemodule_from_id('lti', $id, 0, false, MUST_EXIST);
                $id = $cm->instance;
            }
            $item = grade_get_grades($COURSE->id, 'mod', 'lti', $id);
            if ($item && $item->items) {
                $this->params['item_id'] = $item->items[0]->id;
            }
        } catch (\Exception $e) {
            $this->params['item_id'] = 0;
        }
        $this->params['result_id'] = $USER->id;
        $value = str_replace('$Result.url', parent::get_endpoint(), $value);
        return $value;
    }
}
