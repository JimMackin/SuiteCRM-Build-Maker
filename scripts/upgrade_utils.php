<?php
global $sugar_version;
if(!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');

}
/*********************************************************************************
 * SugarCRM Community Edition is a customer relationship management program developed by
 * SugarCRM, Inc. Copyright (C) 2004-2013 SugarCRM Inc.
 * 
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY SUGARCRM, SUGARCRM DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 * 
 * You can contact SugarCRM, Inc. headquarters at 10050 North Wolfe Road,
 * SW2-130, Cupertino, CA 95014, USA. or at email address contact@sugarcrm.com.
 * 
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 * 
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * SugarCRM" logo. If the display of the logo is not reasonably feasible for
 * technical reasons, the Appropriate Legal Notices must display the words
 * "Powered by SugarCRM".
 ********************************************************************************/


require_once('include/database/DBManagerFactory.php');
///////////////////////////////////////////////////////////////////////////////
////	UPGRADE UTILS
/**
 * upgrade wizard logging
 */
function _logThis($entry) {
	if(function_exists('logThis')) {
		logThis($entry);
	} else {

		$log = clean_path(getcwd().'/upgradeWizard.log');
		// create if not exists
		if(!file_exists($log)) {
			$fp = fopen($log, 'w+'); // attempts to create file
			if(!is_resource($fp)) {
				$GLOBALS['log']->fatal('UpgradeWizard could not create the upgradeWizard.log file');
			}
		} else {
			$fp = fopen($log, 'a+'); // write pointer at end of file
			if(!is_resource($fp)) {
				$GLOBALS['log']->fatal('UpgradeWizard could not open/lock upgradeWizard.log file');
			}
		}

		$line = date('r').' [UpgradeWizard] - '.$entry."\n";

		if(fwrite($fp, $line) === false) {
			$GLOBALS['log']->fatal('UpgradeWizard could not write to upgradeWizard.log: '.$entry);
		}

		fclose($fp);
	}
}

/**
 * This is specific for MSSQL. Before doing an alter table statement for MSSQL, this funciton will drop all the constraint
 * for that column
 */
 function dropColumnConstraintForMSSQL($tableName, $columnName) {
	global $sugar_config;
	if($sugar_config['dbconfig']['db_type'] == 'mssql') {
    	$db = DBManagerFactory::getInstance();
    	$query = "declare @name nvarchar(32), @sql nvarchar(1000)";

		$query = $query . " select @name = sys.objects.name from sys.objects where type_desc like '%CONSTRAINT' and (OBJECT_NAME(parent_object_id) like '%{$tableName}%') and sys.objects.object_id in (select default_object_id from sys.columns where name like '{$columnName}')";

		$query = $query . " begin
		    select @sql = 'ALTER TABLE {$tableName} DROP CONSTRAINT [' + @name + ']'
		    execute sp_executesql @sql
		end";

		$db->query($query);
	} // if
 } // fn

/**
 * gets Upgrade version
 */
function getUpgradeVersion() {
	$version = '';

	if(isset($_SESSION['sugar_version_file']) && !empty($_SESSION['sugar_version_file']) && is_file($_SESSION['sugar_version_file'])) {
		// do an include because the variables will load locally, and it will only popuplate in here.
		include($_SESSION['sugar_version_file']);
		return $sugar_db_version;
	}

	return $version;
}

// moving rebuild js to upgrade utils

function rebuild_js_lang(){
	require_once('include/utils/file_utils.php');
    global $sugar_config;

    $jsFiles = array();
    getFiles($jsFiles, $sugar_config['cache_dir'] . 'jsLanguage');
    foreach($jsFiles as $file) {
        unlink($file);
    }

    if(empty($sugar_config['js_lang_version']))
    	$sugar_config['js_lang_version'] = 1;
    else
    	$sugar_config['js_lang_version'] += 1;

    write_array_to_file( "sugar_config", $sugar_config, "config.php");

    //remove lanugage cache files
    require_once('include/SugarObjects/LanguageManager.php');
    LanguageManager::clearLanguageCache();
}

function clear_SugarLogic_cache(){
    require_once('include/utils/file_utils.php');
    global $sugar_config;

    $files = array();
    getFiles($files, $sugar_config['cache_dir'] . 'Expressions');
    foreach($files as $file) {
        unlink($file);
    }
}


/**
 * update DB version and sugar_version.php
 */

function upgradeDbAndFileVersion($version) {
	global $instancePath;
	if(!isset($instancePath) && isset($_SESSION['instancePath'])){
		 $instancePath = $_SESSION['instancePath'];
	}
	if(!function_exists('updateVersions')) {
		if(file_exists('modules/UpgradeWizard/uw_utils.php')){
			require_once('modules/UpgradeWizard/uw_utils.php');
		}
		elseif(file_exists($instancePath.'/modules/UpgradeWizard/uw_utils.php')){
			require_once($instancePath.'/modules/UpgradeWizard/uw_utils.php');
		}
	}
	updateVersions($version);
}
////	END UPGRADE UTILS
///////////////////////////////////////////////////////////////////////////////

///////////////////////////////////////////////////////////////////////////////
////	SCHEMA CHANGE PRIVATE METHODS
function _run_sql_file($filename) {
	global $path;

    if(!is_file($filename)) {
    	_logThis("*** ERROR: Could not find file: {$filename}", $path);
        return(false);
    }

    $fh         = fopen($filename,'r');
    $contents   = fread($fh, filesize($filename));
    fclose($fh);

    $lastsemi   = strrpos($contents, ';') ;
    $contents   = substr($contents, 0, $lastsemi);
    $queries    = explode(';', $contents);
    $db         = DBManagerFactory::getInstance();

	foreach($queries as $query){
		if(!empty($query)){
			_logThis("Sending query: ".$query, $path);
			if($db->dbType == 'oci8') {
			} else {
				$query_result = $db->query($query.';', true, "An error has occured while performing db query.  See log file for details.<br>");
			}
		}
	}

	return(true);
}

////	END SCHEMA CHANGE METHODS
///////////////////////////////////////////////////////////////////////////////


///////////////////////////////////////////////////////////////////////////////
////	FIX THINGS IN UPGRADE FUNCTIONS
////	END FIX THINGS IN UPGRADE FUNCTIONS
///////////////////////////////////////////////////////////////////////////////
?>
