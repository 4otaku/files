<?php

abstract class Action_Abstract
{
	protected $type = '';

	protected $success = false;
	protected $error = false;
	protected $data = array();
	protected $file = false;
	protected $file_mode = false;
	protected $file_name = false;
	protected $force_download = false;
	protected $id = false;
	protected $id_local = false;
	protected $dir = '';

	protected $error_messages = array(
		Error_Upload::NOT_FOUND => 'empty',
		Error_Upload::EMPTY_FILE => 'filetype',
		Error_Upload::FILE_TOO_LARGE => 'maxsize',
		Error_Upload::NOT_AN_IMAGE => 'filetype',
		Error_Upload::ALREADY_EXISTS => 'exists',
		Error_Upload::NOT_A_TORRENT => 'filetype',
	);

	public function __construct($id) {
		if ($id && is_numeric($id) && $this->type) {
			$data = Database::get_row('item', array('id', 'dir'),
				'type = ? and id_type = ?', array($this->type, $id));
		}

		if (!empty($data)) {
			$this->id_local = $id;
			$this->id = $data['id'];
			$this->dir = $data['dir'];
		}
	}

	public function upload() {
		if (!empty($_FILES)) {
			$file = current(($_FILES));

			$file = $file['tmp_name'];
			$name = $file['name'];
		} elseif ($_GET['qqfile']) {

			$file = file_get_contents('php://input');
			$name = urldecode($_GET['qqfile']);
		} else {
			$this->error = Error_Upload::EMPTY_FILE;
		}

		if (empty($file) || empty($name)) {
			return;
		}

		try {
			$worker = 'Upload_' . ucfirst($this->type);
			$worker = new $worker($file, $name);
			$this->data = $worker->process_file();
			$this->success = true;
		} catch (Error_Upload $e) {
			$this->error = $e->getCode();
		}
	}

	public function info($params) {
		if (!$this->id) {
			$this->error = Error::NOT_FOUND;
			return;
		}

		$values = array($this->id);
		$condition = 'id = ?';
		if (!empty($params)) {
			$key = reset($params);
			$values[] = $key;
			$condition .= ' and key = ?';
		}

		$this->data = (array) Database::get_vector('info',
			array('key', 'value'), $condition, $values);
		$this->success = true;
	}

	public function show($params, $file = 'original') {
		$this->file_mode = true;

		if (!$this->dir) {
			$this->error = Error::NOT_FOUND;
			return;
		}

		$file = FILES . DS . $this->dir . DS . $file;
		if (!file_exists($file)) {
			$this->error = Error::NOT_FOUND;
			return;
		}

		if (!empty($params)) {
			$this->file_name = array_shift($params);
		} else {
			$this->set_default_name();
		}

		if (empty($this->file_name)) {
			$this->error = Error::NOT_FOUND;
			return;
		}

		$this->file = $file;
		$this->success = true;
	}

	protected function set_default_name() {
		$info = (array) Database::get_vector('info',
			array('key', 'value'), 'id = ? and key in (?,?,?)',
			array($this->id, 'name', 'md5', 'extension'));

		if (!empty($info['name'])) {
			$this->file_name = $info['name'];
			return;
		}

		if (!empty($info['md5']) && !empty($info['extension'])) {
			$this->file_name = $info['md5'] . '.' . $info['extension'];
			return;
		}
	}

	public function download($params) {
		$this->force_download = true;
		$this->show($params);
	}

	public function small($params) {
		if (empty($params)) {
			$this->error = Error::NOT_FOUND;
			return;
		}

		$file = array_shift($params);
		$this->show($params, $file);
	}

	public function output() {
		if (!$this->file_mode) {
			$this->output_data();
		} else {
			$this->output_file();
		}
	}

	protected function output_data() {
		$data = $this->data;
		if ($this->error) {
			$data['error'] = isset($this->error_messages[$this->error]) ?
				$this->error_messages[$this->error] : $this->error;
		}
		$data['success'] = $this->success;

		header('Content-type: application/json');
		echo json_encode($data);
		die;
	}

	protected function output_file() {
		if (!$this->file) {
			header('HTTP/1.x 404 Not Found');
			die;
		}

		$size = filesize($this->file);

		$range = '';
		if (isset($_SERVER['HTTP_RANGE'])) {
			$range = explode('=', $_SERVER['HTTP_RANGE'], 2);
			$size_init = array_shift($range);
			if ($size_unit == 'bytes' && !empty($range)) {
				$range = reset($range);
				$range = explode(',', $range, 2);
				$range = reset($range);
			}
		}

		if (strpos($range, '-')) {
			list($seek_start, $seek_end) = explode('-', $range, 2);
		}

		$seek_end = (empty($seek_end)) ? ($size - 1) :
			min(abs(intval($seek_end)), ($size - 1));
		$seek_start = (empty($seek_start) || $seek_end < abs(intval($seek_start))) ? 0 :
			max(abs(intval($seek_start)), 0);

		if ($seek_start > 0 || $seek_end < ($size - 1)) {
			header('HTTP/1.1 206 Partial Content');
		}

		header('Accept-Ranges: bytes');
		header('Content-Range: bytes '.$seek_start.'-'.$seek_end.'/'.$size);

		header('Content-Disposition: attachment; filename="' . $this->file_name . '"');
		header('Content-Length: '.($seek_end - $seek_start + 1));

		$fp = fopen($this->file, 'rb');
		fseek($fp, $seek_start);

		while(!feof($fp)) {
			set_time_limit(0);
			print(fread($fp, 1024*1024));
			flush();
			ob_flush();
		}

		fclose($fp);
	}
}
