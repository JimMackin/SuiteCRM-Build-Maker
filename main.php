#!/usr/bin/php
<?php
require_once 'vendor/autoload.php';
error_reporting(E_ALL);

$suitecrmGitLoc = 'https://github.com/salesagility/SuiteCRM';
$repoLoc = './SuiteCRMRepo';
$maker = new SuiteCRMBuildMaker($repoLoc,$suitecrmGitLoc);

function showUsage(){
    $script = __FILE__;
?>
Usage:
    <?= $script ?> installer <tag>
    <?= $script ?> upgrader <from-tag> <to-tag>
    <?= $script ?> help
<?php
}

if(count($argv) < 2){
    showUsage();
    die();
}
$command = $argv[1];
switch($command){
    case 'installer':
        if(count($argv) < 3){
            showUsage();
            return 1;
        }
        return $maker->doInstallerPackage($argv[2]);
    case 'upgrader':
        if(count($argv) < 3){
            showUsage();
            return 1;
        }
        return $maker->doUpgradePackage($argv[2],$argv[3]);
        break;
    case 'help':
        showUsage();
        return 0;
    default:
        echo "Unrecognised command\n";
        showUsage();
        return 1;
}


class SuiteCRMBuildMaker{

    var $verbosity = SuiteCRMBuildMaker::NORMAL_LOGGING;
    var $git;
    var $repoLoc;
    var $gitLoc;
    var $fromTag;
    var $toTag;
    var $blackList;
    const QUIET_LOGGING = 0;
    const NORMAL_LOGGING = 1;
    const VERBOSE_LOGGING = 2;

    public function __construct($repoLoc, $gitLoc){
        $this->repoLoc = $repoLoc;
        $this->gitLoc = $gitLoc;
        $this->setupGit();
        $this->blackList = array('.','..','.git','.gitignore','README.md');
    }

    public function log($msg, $verbosity){
        if($verbosity <= $this->verbosity){
            echo $msg . "\n";
        }
    }

    public function doInstallerPackage($tag){
        $this->log("Creating package for $tag", SuiteCRMBuildMaker::NORMAL_LOGGING);
        $this->git->checkout("tags/$tag");
        $zip = new ZipArchive();
        $filename = "./".$tag.".".date('Ymd').".zip";
        if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
            $this->log("Cannot open <$filename>",SuiteCRMBuildMaker::QUIET_LOGGING);
            return 2;
        }
        foreach($this->getInstallerFiles($this->repoLoc) as $file){
            $zip->addFile($file, substr($file,strlen($this->repoLoc)));
        }
        $zip->close();
        $this->log("Written $tag installer to $filename",SuiteCRMBuildMaker::NORMAL_LOGGING);
        return 0;
    }

    public function doUpgradePackage($fromTag, $toTag){
      $this->log("Creating upgrade package for $fromTag to $toTag", SuiteCRMBuildMaker::NORMAL_LOGGING);
      $outFiles = $this->getModifiedFiles($fromTag, $toTag);

      $zip = new ZipArchive();
      $filename = "SuiteCRM-Upgrade-$fromTag-to-$toTag.zip";
      $fileDir = "SuiteCRM-Upgrade-$fromTag-to-$toTag";

      if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
          $this->log("Cannot open <$filename>", SuiteCRMBuildMaker::QUIET_LOGGING);
          return 1;
      }
      $this->log("Created $filename", SuiteCRMBuildMaker::NORMAL_LOGGING);

      //Get list of files
      //Add to zip
      foreach($outFiles as $out){
          if(!is_file($this->repoLoc."/".$out)){
              continue;
          }
          $res = $zip->addFile($this->repoLoc."/".$out,$fileDir."/".$out);
          if(!$res){
              $this->log("Failed adding file $out", SuiteCRMBuildMaker::QUIET_LOGGING);
              return 1;
          }
          $this->log("Added $out", SuiteCRMBuildMaker::VERBOSE_LOGGING);
      }
      //Add manifest
      $res = $zip->addFromString('manifest.php',$this->getManifestString($fromTag,$toTag));
      if(!$res){
          $this->log("Failed adding manifest", SuiteCRMBuildMaker::QUIET_LOGGING);
          return 1;
      }
      $zip->addEmptyDir('scripts/');
      $this->addDirectoryToZip($zip,'scripts',$this->blackList);
      $deletedFiles = $this->getDeletedFiles($fromTag, $toTag);
      $filesToRemove = file('scripts/files_to_remove/files.txt');
      foreach($deletedFiles as $deletedFile){
        $this->log("Deleted files: $deletedFile", SuiteCRMBuildMaker::VERBOSE_LOGGING);
        $filesToRemove[] = $deletedFile;
      }
      $filesToRemove = array_unique($filesToRemove);
      $zip->addFromString('scripts/files_to_remove/files.txt',implode("\n",$filesToRemove));

      $zip->close();
      $this->log("Finished creating $filename", SuiteCRMBuildMaker::NORMAL_LOGGING);
    }

    private function setupGit(){
        $this->git = new PHPGit\Git();
        if(!file_exists($this->repoLoc . DIRECTORY_SEPARATOR . '.git')){
            $this->log("No repo! Cloning {$this->repoLoc} now...", SuiteCRMBuildMaker::NORMAL_LOGGING);
            $this->git->clone($this->gitLoc, $this->repoLoc);
        }
        $this->git->setRepository($this->repoLoc);
        $this->git->fetch('origin');
    }

    function getDeletedFiles($fromTag, $toTag){
        $this->log("Getting deleted files between $fromTag and $toTag", SuiteCRMBuildMaker::VERBOSE_LOGGING);
        $command = "cd {$this->repoLoc}; git diff --name-only 'tags/{$fromTag}' 'tags/{$toTag}' --diff-filter=D";
        $outFiles = array();
        exec($command,$outFiles);
        return $outFiles;
    }

    function getModifiedFiles($fromTag, $toTag){
        $this->log("Getting modified files between $fromTag and $toTag", SuiteCRMBuildMaker::VERBOSE_LOGGING);
        $command = "cd {$this->repoLoc}; git diff --name-only 'tags/{$fromTag}' 'tags/{$toTag}' --diff-filter=ACMRT";
        $outFiles = array();
        exec($command,$outFiles);
        return $outFiles;
    }

    private function addDirectoryToZip($zip,$directory,$blackList){
        foreach(scandir($directory) as $file){
           if(in_array($file,$blackList)){
               continue;
           }
           $file = $directory."/".$file;
           if(is_dir($file)){
               $this->log("Adding directory $file", SuiteCRMBuildMaker::VERBOSE_LOGGING);
               $zip->addEmptyDir($file);
               $this->addDirectoryToZip($zip,$file,$blackList);
           }else{
               $this->log("Adding file $file", SuiteCRMBuildMaker::VERBOSE_LOGGING);
               $zip->addFile($file);
           }
        }

    }

    private function getInstallerFiles($repoLoc){
        $files = array();
        $blacklist = array('.','..','.git','.gitignore','README.md');
        foreach(scandir($repoLoc) as $file){
            if(in_array($file,$this->blackList)){
                continue;
            }
            $file = $repoLoc."/".$file;
            if(is_dir($file)){
                $files = array_merge($files,$this->getInstallerFiles($file));
            }else{
                $files[$file] = $file;
            }
        }
        return $files;
    }

    private function getUpgradeManifestArray($fromTag, $toTag){
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

    private function getManifestString($fromTag,$toTag){
        $manifest = $this->getUpgradeManifestArray($fromTag,$toTag);
        $str = "<?php\n";
        $str .= "// SuiteCRM Install Builder\n";
        $str .= '$manifest = '.var_export($manifest,true).";\n";
        $str .= "?>\n";
        return $str;
    }

}
