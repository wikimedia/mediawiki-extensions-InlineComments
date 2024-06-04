<?php
$config = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$config['directory_list'] = array_merge(
	$config['directory_list'],
	[
		'../../extensions/Echo'
	]
);
$config['exclude_analysis_directory_list'] = array_merge(
	$config['exclude_analysis_directory_list'],
	[
		'../../extensions/Echo'
	]
);

return $config;
