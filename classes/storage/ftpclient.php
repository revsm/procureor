<?php
define('PR_FTP_STATE_DISCONNECTED', 0);
define('PR_FTP_STATE_CONNECTED', 1);
define('PR_FTP_STATE_LOGGED_IN', 2);
define('PR_FTP_STATE_TARGETED', 3);

define('PR_FTP_STATUS_ERROR', -1);
define('PR_FTP_STATUS_READY', 0);
define('PR_FTP_STATUS_READING_LIST', 1);
define('PR_FTP_STATUS_NOT_READY', 2);

class ProcuFTPClient
{
    private $FTP_STATE_NAME;
    private $FTP_STATUS_NAME;
    
    private $user;
    private $pass;
    private $port;
    private $host;
    
    private $listing_cache;
    
    private $cur_path;
    
    private $timeout = 10;
    private $conn_id;
    private $systype;
    
    private $months;
    
    private $state = PR_FTP_STATE_DISCONNECTED;
    private $status = PR_FTP_STATUS_NOT_READY;
    
    function __construct($connect_data)
    {
        $this->FTP_STATE_NAME  = array(
            0 => 'DISCONNECTED',
            1 => 'CONNECTED',
            2 => 'LOGGED IN',
            3 => 'TARGETED'
        );
        $this->FTP_STATUS_NAME = array(
            -1 => 'ERROR',
            0 => 'READY',
            1 => 'READING',
            2 => 'NOT READ'
        );
        
        $this->months = array(
            "Jan" => 0,
            "Feb" => 1,
            "Mar" => 2,
            "Apr" => 3,
            "May" => 4,
            "Jun" => 5,
            "Jul" => 6,
            "Aug" => 7,
            "Sep" => 8,
            "Oct" => 9,
            "Nov" => 10,
            "Dec" => 11
        );
        
        $this->user = $connect_data['username'];
        $this->pass = $connect_data['password'];
        
        $this->listing_cache = array();
        
        if (isset($connect_data['port'])) {
            $this->port = $connect_data['port'];
        } else {
            $this->port = 21;
        }
        
        $this->host = $connect_data['host'];
        
        if (isset($connect_data['path'])) {
            $this->cur_path = $connect_data['path'];
        } else {
            $this->cur_path = '';
        }
    }
    
    public function getState()
    {
        return $this->state;
    }
    
    public function getStatus()
    {
        return $this->status;
    }
    
    private function updateState($state)
    {
        $this->printLog("State Changed: " . $this->FTP_STATE_NAME[$this->state] . "->" . $this->FTP_STATE_NAME[$state]);
        $this->state = $state;
    }
    
    private function updateStatus($status)
    {
        $this->printLog("Status Changed: " . $this->FTP_STATUS_NAME[$this->status] . "->" . $this->FTP_STATUS_NAME[$status]);
        $this->status = $status;
    }
    
    private function printLog($msg)
    {
        echo "[" . date("d/m/Y h:m:i", time()) . "] " . $msg . "\n";
    }
    
    public function connect()
    {
        $this->printLog("Connect");
        
        if ($this->conn_id = ftp_connect($this->host, $this->port, $this->timeout)) {
            if (ftp_login($this->conn_id, $this->user, $this->pass)) {
                $this->updateState(PR_FTP_STATE_LOGGED_IN);
                if ($status = ftp_chdir($this->conn_id, $this->cur_path)) {
                    $this->updateState(PR_FTP_STATE_TARGETED);
                    $this->updateStatus(PR_FTP_STATUS_READY);
                    
                    $this->systype = ftp_systype($this->conn_id);
                    
                    // TODO: make specific OS dependednt things
                    $this->printLog("OS: " . $this->systype . " " . ftp_pwd($this->conn_id));
                    
                    // TODO: pass the mode into the module
                    ftp_pasv($this->conn_id, true);
                    
                    unset($this->listing_cache);
                    $this->listing_cache = array();
                    
                } else {
                    $this->updateState(PR_FTP_STATE_ERROR);
                }
            } else {
                $this->updateState(PR_FTP_STATE_DISCONNECTED);
                $this->updateStatus(PR_FTP_STATUS_NOT_READY);
            }
        } else {
            $this->updateState(PR_FTP_STATE_DISCONNECTED);
            $this->updateStatus(PR_FTP_STATUS_NOT_READY);
        }
        
    }
    
    public function disconnect()
    {
        $this->printLog("Disconnect");
        if ($this->state == PR_FTP_STATE_DISCONNECTED && $this->conn_id) {
            ftp_close($this->conn_id);
        }
        
        $this->updateState(PR_FTP_STATE_DISCONNECTED);
        $this->updateStatus(PR_FTP_STATUS_NOT_READY);
    }
    
    private function obtainFileTree($storage_callback, $storage_maxitems, $path = '/', $cur_depth = 0, $max_depth = 3)
    {
        $this->printLog("Get List [" . $path . "]");
        
        $arBuffer = ftp_rawlist($this->conn_id, $path);
        
        if (!empty($arBuffer)) {
            foreach ($arBuffer as $line) {
                $fields = preg_split("/\s+/", $line, 9);
                $hour   = $minute = 0;
                $year   = $fields[7];
                if (strpos($fields[7], ":")) {
                    list($hour, $minute) = explode(":", $fields[7]);
                    $year = date("Y");
                }
                
                $ftp_entry = array(
                    "name" => $fields[8], // TODO: parse symlinks 
                    // "www -> ."    "mnegrecy.bget.ru/public_html/www -> ."    l    lrwxrwxrwx    60500    601    1    1421182800
                    "fullpath" => $path . $fields[8],
                    "type" => $fields[0]{0},
                    "permissions" => $fields[0], // TODO: convert into number
                    "owner" => $fields[2],
                    "usergroup" => $fields[3],
                    "size" => $fields[4],
                    "date" => mktime($hour, $minute, 0, $this->months[$fields[5]], $fields[6], $year) // TODO: check for valid values
                );
                
                array_push($this->listing_cache, $ftp_entry);
                
                if ($ftp_entry["type"] == "d") {
                    if ($cur_depth < $max_depth) {
                        $ftp_entry["fullpath"] .= "/"; // TODO: use system-specific slash
                        $this->obtainFileTree($storage_callback, $storage_maxitems, $ftp_entry["fullpath"], $cur_depth + 1, $max_depth);
                    }
                }
            }
        }
        
        if ((count($this->listing_cache) >= $storage_maxitems) || ($cur_depth == 0)) {
            call_user_func($storage_callback, $this->listing_cache);
            $this->listing_cache = array();
        }
    }
    
    public function prepareListing($storage_callback, $storage_maxitems)
    {
        $this->updateStatus(PR_FTP_STATUS_READING_LIST);
        $this->obtainFileTree($storage_callback, $storage_maxitems);
        
        $this->updateStatus(PR_FTP_STATUS_READY);
        return PR_LISTING_CACHE_FILE;
    }
    
} // end of class
 