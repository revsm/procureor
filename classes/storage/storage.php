<?php

include 'ftpclient.php';

define('PROCUSTORAGE_IFACE_FTP', 1);
define('PROCUSTORAGE_IFACE_SSH', 2);

define('PR_LISTING_CACHE_FILE', 'pcr_listing_cache');
define('PR_MAX_CACHE_LINES', 1000);

define('PROCUSTOR_NAME', 0);
define('PROCUSTOR_FULL_NAME', 1);
// TODO: add other indices

define('PROCU_NOT_SET', -1);

class ProcuStorage
{
    
    private $iface;
    private $client;
    private $cached_listing;
    private $working_tmp_folder;
    
    function __construct($iface, $credentials)
    {
        $this->working_tmp_folder = '';

        $this->iface = $iface;
        switch ($this->iface) {
            case PROCUSTORAGE_IFACE_SSH:
                break; // TODO: add interface 
            case PROCUSTORAGE_IFACE_FTP:
            default:
                $this->client = new ProcuFTPClient($credentials);
                $this->client->connect();
                break;
        }
    }
    
    private function printLog($msg)
    {
//        echo "[" . date("d/m/Y h:m:i", time()) . "] " . $msg . "\n";
    }
    
    static function cacheData($data)
    {
        $fp = fopen(PR_LISTING_CACHE_FILE, 'a');
        
        foreach ($data as $items) {
            fputcsv($fp, $items, "\t");
        }
        
        fclose($fp);
        
    }
    
    public function prepare($use_cache)
    {
        // TODO: add check for file existance, etc
        if (!($use_cache && file_exists(PR_LISTING_CACHE_FILE))) {
            @unlink(PR_LISTING_CACHE_FILE);
            $this->client->prepareListing('ProcuStorage::cacheData', PR_MAX_CACHE_LINES);
        }
        
        $fp                   = fopen(PR_LISTING_CACHE_FILE, 'r');
        $this->cached_listing = array();
        while (($data = fgetcsv($fp, 0, "\t")) != 0) {
            array_push($this->cached_listing, $data);
        }
        
        fclose($fp);
        
        $this->printLog("Loaded " . count($this->cached_listing) . " from cache");
    }
    
    public function finalize()
    {
        $this->client->disconnect();
    }
    
    public function findBySize($more_than, $less_than)
    {
        // TODO: 
    }
    
    public function findByTimeRange($older_than, $younger_than)
    {
        // TODO: 
    }
    
    public function findByPermission($owner = PROCU_NOT_SET, $group = PROCU_NOT_SET, $others = PROCU_NOT_SET)
    {
        // TODO: 
    }
    
    public function findByName($name_fragment, $case = false, $single = false)
    {
        $res           = array();
        $name_fragment = str_replace('*', '@ANY@', $name_fragment);
        $name_fragment = preg_quote($name_fragment);
        $name_fragment = str_replace('@ANY@', '.*', $name_fragment);
        
        $num = count($this->cached_listing);
        $modif = '';

        if (!$case) {
          $modif = 'i';
        }
        
        for ($i = 0; $i < $num; $i++) {
            $f = false;
            
            if (preg_match('~' . $name_fragment . '~' . $modif, $this->cached_listing[$i][PROCUSTOR_NAME], $found)) {
                array_push($res, $i);
                $this->printLog("Found " . implode("\t", $this->cached_listing[$i]));
                
                if ($single) {
                    break;
                }
            }
        }
        
        return $res;
    }
    
    public function getFile($index)
    {
        // TODO: implement
        $this->printLog("get " . $this->cached_listing[$index][PROCUSTOR_FULL_NAME]);
        $this->client->download($this->cached_listing[$index][PROCUSTOR_FULL_NAME], $this->working_tmp_folder . '/download/' . $this->cached_listing[$index][PROCUSTOR_FULL_NAME]);
    }
    
    public function rmFile($index)
    {
        $this->printLog("rm " . $this->cached_listing[$index][PROCUSTOR_FULL_NAME]);
        return ($this->client->delete($this->cached_listing[$index][PROCUSTOR_FULL_NAME]) == PROCU_FTP_OK);
    }
    
    public function backupFile($index)
    {
        $this->printLog("backup " . $this->cached_listing[$index][PROCUSTOR_FULL_NAME]);

        // TODO: implement status
        $this->client->download($this->cached_listing[$index][PROCUSTOR_FULL_NAME], $this->working_tmp_folder . '/backup/' . md5($this->cached_listing[$index][PROCUSTOR_FULL_NAME]));
    }
    
    public function restoreFile($index)
    {
        $this->printLog("restore " . $this->cached_listing[$index][PROCUSTOR_FULL_NAME]);

        // TODO: implement status
        $this->client->upload($this->working_tmp_folder . '/backup/' . md5($this->cached_listing[$index][PROCUSTOR_FULL_NAME]), $this->cached_listing[$index][PROCUSTOR_FULL_NAME]);
    }
    
    public function get($list_or_file)
    {
        if (is_array($list_or_file)) {
            foreach ($list_or_file as $file) {
                $this->getFile($file);
            }
        } else {
            $this->getFile($list_or_file);
        }
    }
    
    public function rm($list_or_file)
    {
        if (is_array($list_or_file)) {
            foreach ($list_or_file as $file) {
                $this->rmFile($file);
            }
        } else {
            $this->rmFile($list_or_file);
        }
    }
    
    public function backup($list_or_file)
    {
        if (is_array($list_or_file)) {
            foreach ($list_or_file as $file) {
                $this->backupFile($file);
            }
        } else {
            $this->backupFile($list_or_file);
        }
    }
    
    public function restore($list_or_file)
    {
        if (is_array($list_or_file)) {
            foreach ($list_or_file as $file) {
                $this->restoreFile($file);
            }
        } else {
            $this->restoreFile($list_or_file);
        }
    }
    
    
} // end of class


////////////////////////////////////////////////////////////////////////////////////////
// Test
////////////////////////////////////////////////////////////////////////////////////////
date_default_timezone_set('Europe/Moscow');

$cred['username'] = '';
$cred['password'] = '';
$cred['host']     = '';
$cred['path']     = '/';
$cred['iface']    = PROCUSTORAGE_IFACE_FTP;

$test_storage = new ProcuStorage(PROCUSTORAGE_IFACE_FTP, $cred);
$test_storage->prepare(true);
//$list = $test_storage->findByName('*.html');
//$test_storage->rm($list);
$test_storage->finalize();
