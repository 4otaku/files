<?php

include 'framework/init.php';

Autoload::init(array(LIBS, FRAMEWORK_LIBS, FRAMEWORK_EXTERNAL), CACHE);

Config::parse('define.ini', true);
Cache::$base_prefix = Config::get('cache', 'prefix');

$url = explode('/', preg_replace('/\?[^\/]+$/', '', $_SERVER['REQUEST_URI']));
$url = array_filter($url);

if (count($url) >= 2) {
	$worker = 'error';
	$action = 'display';
} else {
	$worker = array_shift($url);
	$action = array_shift($url);

	$id = false;
	if ($action != 'upload') {
		if (empty($url)) {
			$worker = 'error';
			$action = 'display';
		} else {
			$id = array_shift($url);
		}
	}
}

$worker = 'Action_' . ucfirst($worker);
$worker = new $worker($id);
$worker->$action($url)->output();
