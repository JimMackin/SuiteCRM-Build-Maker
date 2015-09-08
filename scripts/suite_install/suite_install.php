<?php

global $sugar_config, $db;
$sugar_config['default_max_tabs'] = 10;
$sugar_config['suitecrm_version'] = '7.2.1';

ksort($sugar_config);
write_array_to_file('sugar_config', $sugar_config, 'config.php');

require_once(clean_path($unzip_dir.'/scripts/suite_install/AdvancedOpenPortal.php'));
update_aop();

