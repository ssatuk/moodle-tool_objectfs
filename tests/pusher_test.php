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
 * local_catdeleter scheduler tests.
 *
 * @package   local_catdeleter
 * @author    Kenneth Hendricks <kennethhendricks@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(__DIR__ . '/mock/sss_mock_client.php');
require_once(__DIR__ . '/testlib.php');

use tool_sssfs\sss_file_system;
use tool_sssfs\file_manipulators\pusher;

class tool_sssfs_pusher_testcase extends advanced_testcase {


    protected function setUp() {
        global $CFG;
        $this->resetAfterTest(true);
        $CFG->filesystem_handler_class = '\tool_sssfs\sss_file_system';
        $this->config = generate_config();
        $this->client = new sss_mock_client();
        $this->filesystem = sss_file_system::instance();
        $this->filesystem->set_sss_client($this->client);
        ob_start(); // Start a buffer to catch all the mtraces in the task.

    }

    protected function tearDown() {
        ob_end_clean(); // Throw away the buffer content.
    }

    public function test_can_push_file() {
        global $DB;

        $filepusher = new pusher($this->client, $this->filesystem, $this->config);
        $file = save_file_to_local_storage();
        $filecontenthash = $file->get_contenthash();
        $prepushcount = $DB->count_records('tool_sssfs_filestate', array('contenthash' => $filecontenthash));

        $this->assertEquals(0, $prepushcount); // Assert table does not contain items.

        $contenthashes = $filepusher->get_candidate_content_hashes();
        $filepusher->execute($contenthashes);
        $postpushcount = $DB->count_records('tool_sssfs_filestate', array('contenthash' => $filecontenthash));

        $this->assertEquals(1, $postpushcount); // Assert table has item.
    }

    public function test_wont_push_file_under_threshold() {
        global $DB;

        // Set size threshold of 1000.
        $this->config = generate_config(1000);
        $filepusher = new pusher($this->client, $this->filesystem, $this->config);
        $file = save_file_to_local_storage(100); // Set file size to 100.
        $filecontenthash = $file->get_contenthash();
        $contenthashes = $filepusher->get_candidate_content_hashes();
        $filepusher->execute($contenthashes);
        $postpushcount = $DB->count_records('tool_sssfs_filestate', array('contenthash' => $filecontenthash));

        // Assert table still does not contain entry.
        $this->assertEquals(0, $postpushcount);
    }

    public function test_under_minimum_age_files_are_not_pushed() {
        global $DB;

        // Set minimum age to a large value.
        $this->config = generate_config(0, 99999);
        $filepusher = new pusher($this->client, $this->filesystem, $this->config);
        $file = save_file_to_local_storage();
        $filecontenthash = $file->get_contenthash();
        $contenthashes = $filepusher->get_candidate_content_hashes();
        $filepusher->execute($contenthashes);
        $postpushcount = $DB->count_records('tool_sssfs_filestate', array('contenthash' => $filecontenthash));

        // Assert table still does not contain entry.
        $this->assertEquals(0, $postpushcount);
    }


    public function test_sss_client_push_file_execption_catch () {
        global $DB;
        $filepusher = new pusher($this->client, $this->filesystem, $this->config);
        $filecontenthash = 'not_a_hash';
        $filepusher->execute(array($filecontenthash));
        $postpushcount = $DB->count_records('tool_sssfs_filestate', array('contenthash' => $filecontenthash));
        $this->assertEquals(0, $postpushcount); // Assert table still does not contain entry.
    }

    public function test_max_task_runtime () {
        global $DB;

        // Set max runtime to 0.
        $this->config = generate_config(0, -10, 0);

        $filepusher = new pusher($this->client, $this->filesystem, $this->config);
        $file = save_file_to_local_storage();
        $filecontenthash = $file->get_contenthash();
        $contenthashes = $filepusher->get_candidate_content_hashes();
        $filepusher->execute($contenthashes);
        $postpushcount = $DB->count_records('tool_sssfs_filestate', array('contenthash' => $filecontenthash));

        // Assert table does not contain entry.
        $this->assertEquals(0, $postpushcount);
    }

    public function test_saves_md5_hash () {
        global $DB;

        $filepusher = new pusher($this->client, $this->filesystem, $this->config);
        $file = save_file_to_local_storage();
        $expectedcontent = 'This is my files content';
        $file = save_file_to_local_storage(100, 'testfile.txt', $expectedcontent);
        $filecontenthash = $file->get_contenthash();
        $expectedmd5 = md5($expectedcontent);
        $contenthashes = $filepusher->get_candidate_content_hashes();
        $filepusher->execute($contenthashes);
        $savedrecord = $DB->get_record('tool_sssfs_filestate', array('contenthash' => $filecontenthash));
        $this->assertEquals($expectedmd5, $savedrecord->md5);
    }
}
