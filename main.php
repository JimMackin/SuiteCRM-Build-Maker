#!/usr/bin/php
<?php
require_once 'vendor/autoload.php';
error_reporting(E_ALL);

$suitecrmGitLoc = 'https://github.com/salesagility/SuiteCRM';
$repoLoc = './SuiteCRMRepo';
$blackList = array('.','..','.git','.gitignore','README.md');

if(count($argv) < 2){
    showUsage();
    die();
}
$command = $argv[1];
switch($command){
    case 'installer':
        if(count($argv) < 3){
            showUsage();
            die();
        }
        doInstallerPackage($argv[2],$repoLoc,$suitecrmGitLoc);
        break;
    case 'upgrader':
        if(count($argv) < 3){
            showUsage();
            die();
        }
        doUpgradePackage($argv[2],$argv[3],$repoLoc,$blackList);
        break;
    case 'help':
        showUsage();
        break;
    default:
        echo "Unrecognised command\n";
        showUsage();
        break;
}
die();

function showUsage(){
    $script = __FILE__;
?>
Usage:
    <?= $script ?> installer <tag>
    <?= $script ?> upgrader <from-tag> <to-tag>
    <?= $script ?> help
<?php
}

function doInstallerPackage($currentTag,$repoLoc,$suitecrmGitLoc){
    $git = setupGit($repoLoc,$suitecrmGitLoc);
    $git->checkout("tags/$currentTag");
    $zip = new ZipArchive();
    $filename = "./".$currentTag.".".date('Ymd').".zip";
    if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
        echo "Cannot open <$filename>\n";
        die();
    }
    foreach(getInstallerFiles($repoLoc) as $file){
        $zip->addFile($file, substr($file,strlen($repoLoc)));
    }
    $zip->close();
    echo "Written $currentTag installer to $filename\n";
}

function setupGit($repoLoc,$suitecrmGitLoc){
    $git = new PHPGit\Git();
    if(!file_exists($repoLoc . DIRECTORY_SEPARATOR . '.git')){
        echo "No repo! Cloning $repoLoc now...\n";
        $git->clone($suitecrmGitLoc, $repoLoc);
    }
    $git->setRepository($repoLoc);
    $git->fetch('origin');
    return $git;
}
//$blackList = array('.','..','.git','.gitignore','README.md');

//doUpgradePackage('v7.3','v7.3.1',$repoLoc, $blackList);
die();
//echo "Processing $tag\n";
//$git->checkout("tags/$currentTag");


//$zip = new ZipArchive();
//$filename = "./".$currentTag.".".date('Ymd').".zip";
//if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
//    echo "Cannot open <$filename>\n";
//    die();
//}
function getInstallerFiles($repoLoc){
    $files = array();
    $blacklist = array('.','..','.git','.gitignore','README.md');
    foreach(scandir($repoLoc) as $file){
        if(in_array($file,$blacklist)){
            continue;
        }
        $file = $repoLoc."/".$file;
        if(is_dir($file)){
            $files = array_merge($files,getInstallerFiles($file));
        }else{
            $files[$file] = $file;
        }
//              echo $file."\n";
    }
    return $files;
}

//print_r(getInstallerFiles($repoLoc));
//die();
//foreach(getInstallerFiles($repoLoc) as $file){
//    $zip->addFile($file, substr($file,strlen($repoLoc)));
//}
//$zip->close();


function getDeletedFiles($fromTag, $toTag, $repoLoc){
    $command = "cd $repoLoc; git diff --name-only 'tags/$fromTag' 'tags/$toTag' --diff-filter=D";
    $outFiles = array();
    exec($command,$outFiles);
    return $outFiles;
}

function getModifiedFiles($fromTag, $toTag, $repoLoc){
    $command = "cd $repoLoc; git diff --name-only 'tags/$fromTag' 'tags/$toTag' --diff-filter=ACMRT";
    echo "Command is $command\n";
    $outFiles = array();
    exec($command,$outFiles);
    return $outFiles;
}

function doUpgradePackage($fromTag, $toTag, $repoLoc, $blackList){
    
    //$command = "cd $repoLoc; git diff --name-only 'tags/$fromTag' 'tags/$toTag' --diff-filter=ACMRT";
    //$outFiles = array();
    //exec($command,$outFiles);
    //print_r($outFiles);
    $outFiles = getModifiedFiles($fromTag, $toTag, $repoLoc);
    $deletedFiles = getDeletedFiles($fromTag, $toTag, $repoLoc);
    echo "Deleted files: \n";
    print_r($deletedFiles);
    $zip = new ZipArchive();
    $filename = "SuiteCRM-Upgrade-$fromTag-to-$toTag.zip";
    $fileDir = "SuiteCRM-Upgrade-$fromTag-to-$toTag";
    if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
        echo "Cannot open <$filename>\n";
        die();
    }
    echo "Created $filename\n";

    //Get list of files
    //Add to zip
    foreach($outFiles as $out){
        if(!is_file($repoLoc."/".$out)){
            continue;
        }
        $res = $zip->addFile($repoLoc."/".$out,$fileDir."/".$out);
        if(!$res){
            echo "Failed adding file $out\n";
            var_dump($zip->getStatusString());
            die();
        }
        echo "Added $out\n";
    }
    //Add manifest
    $res = $zip->addFromString('manifest.php',getManifestString($fromTag,$toTag));
    if(!$res){
        echo "Failed adding manifest\n";
        var_dump($zip->getStatusString());
        die();
    }

    $res = $zip->addEmptyDir('scripts');
    if(!$res){
        echo "Failed adding empty scripts directory\n";
        var_dump($zip->getStatusString());
        die();
    }
    $x = 0;
    addDirectoryToZip($zip,'scripts',$blackList);


 //   $deletedFiles = getDeletedFiles($fromTag, $toTag, $repoLoc);
//    echo "Deleted files are:\n";
//print_r($deletedFiles);
    
    //Do git diff for deleted
    //Add to manifest
    var_dump($zip->getStatusString());
    $zip->close();
    //var_dump($zip->getStatusString());
}

function addDirectoryToZip($zip,$directory,$blackList){
    foreach(scandir($directory) as $file){
       if(in_array($file,$blackList)){
           continue;
       }
    //   echo "File is $file\n";
       $file = $directory."/".$file;
       if(is_dir($file)){
           echo "Adding directory $file\n";
           $zip->addEmptyDir($file);
           addDirectoryToZip($zip,$file,$blackList);
       }else{
           echo "Adding file $file\n";
           $zip->addFile($file);
       }
    }

}

function getManifestString($fromTag,$toTag){
    $manifest = getUpgradeManifestArray($fromTag,$toTag);
    $str = "<?php\n";
    $str .= "// SuiteCRM Install Builder\n";
    $str .= '$manifest = '.var_export($manifest,true).";\n";
    $str .= "?>\n";
    return $str;
}
function getUpgradeManifestArray($fromTag, $toTag){
    $manifest = array (
      'acceptable_sugar_flavors' => 
      array (
        0 => 'CE',
      ),
      'acceptable_sugar_versions' => 
      array (
        'exact_matches' => 
        array (
          0 => '6.5.20'
        ),
        'regex_matches' => 
        array (
        ),
      ),
      'author' => 'SalesAgility',
      'copy_files' => 
      array (
        'from_dir' => "SuiteCRM-Upgrade-$fromTag-to-$toTag",
        'to_dir' => '',
        'force_copy' => 
        array (
        ),
      ),
      'description' => '',
      'icon' => '',
      'is_uninstallable' => false,
      'offline_client_applicable' => true,
      'name' => 'SuiteCRM',
      'published_date' => date("Y-m-d"),
      'type' => 'patch',
      'version' => $toTag,
    );
    return $manifest;

}

