<?php


namespace RFM\Repository\FTP;


use function RFM\app;

class FTP
{
    private $conn = null;
    private $host = '';
    private $username = '';
    private $password = '';
    private $port = '';
    private $timeout = '';

    public function __construct($host, $username, $password, $port = 21, $timeout = 90)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    /**
     * @return null|bool
     * @throws \Exception
     */
    public function connect()
    {
        if (!is_null($this->conn)) {
            return true;
        }
        $this->conn = ftp_connect($this->host, $this->port, $this->timeout);
        if (!$this->conn) {
            throw new \Exception('FTP connect fail');
        }
        $login = ftp_login($this->conn, $this->username, $this->password);
        if (!$login) {
            throw new \Exception('FTP login fail');
        }
        ftp_pasv($this->conn, TRUE);
        return $login;
    }

    /**
     * @param $dir
     * @return bool|string
     * @throws \Exception
     */
    public function mkdir($dir)
    {
        $this->connect();
        return ftp_mkdir($this->conn, $dir);
    }

    /**
     * @param $filePath
     * @return false|string
     * @throws \Exception
     */
    public function fileSize($filePath)
    {
        $this->connect();
        return ftp_size($this->conn, $filePath);
    }

    /**
     * @param $dir
     * @return array|false
     * @throws \Exception
     */
    public function listFiles($dir)
    {
        $this->connect();
        return ftp_nlist($this->conn, $dir);
    }

    /**
     * @param $dir
     * @return array|false
     * @throws \Exception
     */
    public function rawListFiles($dir)
    {
        $this->connect();
        return ftp_rawlist($this->conn, $dir, true);
    }

    /**
     * @param $path
     * @param $newName
     * @return bool
     * @throws \Exception
     */
    public function rename($path, $newName)
    {
        $pathInfo = pathinfo($path);
        $fileName = $pathInfo['basename'];
        $this->connect();
        ftp_chdir($this->conn, $pathInfo['dirname']);
        try {
            if (ftp_rename($this->conn, $fileName, $newName)) {
//                ftp_chmod($this->conn, '0755', $newName);
                return $newName;
            } else {
                return false;
            }
        } catch (\Exception $exception) {
            throw $exception;
        }

    }

    public function ftpUrl()
    {
        return "ftp://{$this->username}:{$this->password}@{$this->host}:{$this->port}";
    }

    public function isDir($path)
    {
        $ftpPath = $this->ftpUrl() . $path;
        return is_dir($ftpPath);
    }

    /**
     * @param $path
     * @throws \Exception
     */
    public function getModifyTime($path)
    {
        $this->connect();
        return ftp_mdtm($this->conn, $path);
    }

    /**
     * @param $path
     * @param $localFile
     * @throws \Exception
     */
    public function readFile($path, $localFile)
    {
        $this->connect();
        ftp_fget($this->conn, $localFile, $path, FTP_BINARY);
    }

    public function copyFile($currentPath, $copyPath, $filename)
    {
        $this->connect();
        $tempFolder = sys_get_temp_dir();
        $localFile = $tempFolder . DIRECTORY_SEPARATOR . $filename;
        if (ftp_get($this->conn, $localFile, $currentPath, FTP_BINARY)) {
            if (ftp_put($this->conn, $copyPath, $localFile, FTP_BINARY)) {
                unlink($localFile);
            } else {
                return false;
            }
        } else {
            return false;
        }
        return true;
    }

    /**
     * @param $currentPath
     * @param $copyPath
     * @throws \Exception
     */
    public function copyFolder($currentPath, $copyPath)
    {
        $listFile = $this->listFiles($currentPath);
        $this->mkdir($copyPath);
        foreach ($listFile as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            if (@ftp_chdir($this->conn, $file)) {
                $folderName = pathinfo($file, PATHINFO_BASENAME);
                ftp_chdir($this->conn, "..");
                $copy = $copyPath . '/' . $folderName;
                $copied = $this->copyFolder($file, $copy);
                if (!$copied) {
                    return false;
                }
            } else {
                $filename = pathinfo($file, PATHINFO_BASENAME);
                $copy = $copyPath . '/' . $filename;
                $copied = $this->copyFile($file, $copy, $filename);
                if (!$copied) {
                    return false;
                }
            }
        }
        return true;
    }

}