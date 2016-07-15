<?php defined('BASEPATH') or exit('No direct script access allowed');

class TrackerInline extends Auth_Controller {
	private $userID;

	public function __construct() {
		parent::__construct();

		$this->load->library('vendor/Limiter');
		$this->load->library('form_validation');

		//1000 requests per hour to either AJAX request.
		if($this->limiter->limit('tracker_general', 1000)) {
			$this->output->set_status_header('429', 'Rate limit reached'); //rate limited reached
			exit();
		}

		$this->userID = (int) $this->User->id;
	}

	public function update() {
		$this->form_validation->set_rules('id',      'Chapter ID',    'required|ctype_digit');
		$this->form_validation->set_rules('chapter', 'Chapter',   'required');

		if($this->form_validation->run() === TRUE) {
			$success = $this->Tracker_Model->updateTrackerByID($this->userID, $this->input->post('id'), $this->input->post('chapter'));

			$this->output->set_content_type('text/plain', 'UTF-8');
			$this->output->set_output("1");
		} else {
			$this->output->set_status_header('400', 'Missing/invalid parameters.');
		}
	}

	/***** IMPORT/EXPORT ******/

	public function import() {
		$this->form_validation->set_rules('json', 'JSON String', 'required|is_valid_json');

		if($this->form_validation->run() === TRUE) {
			$status = $this->Tracker_Model->import_tracker_from_json($this->input->post('json'));
			switch($status['code']) {
				case 0:
					//All is good!
					$this->output->set_status_header('200');
					break;
				case 1:
					$this->output->set_status_header('400', 'JSON contains invalid keys');
					break;
				case 2:
					$this->output->set_status_header('400', 'Unable to add some rows from JSON');
					$this->_render_json(json_encode($status['failed_rows']));
					break;
			}
		} else {
			if(!$this->form_validation->isRuleValid('is_valid_json')) {
				$this->output->set_status_header('400', 'File isn\'t valid JSON!');
			} else {
				$this->output->set_status_header('400', 'No file sent');
			}
		}
	}

	public function export() {
		$trackerData = $this->Tracker_Model->export_tracker_from_user_id($this->userID);
		$this->_render_json($trackerData, TRUE);
	}
}