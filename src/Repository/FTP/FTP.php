<?php


namespace RFM\Repository\FTP;


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
        return ftp_rawlist($this->conn, $dir);
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
        $newName = $newName . '.' . $pathInfo['extension'];
        $this->connect();
        ftp_chdir($this->conn, $pathInfo['dirname']);
        return ftp_rename($this->conn, $fileName, $newName);
    }

    public function isDir($path)
    {
        $originalDirectory = ftp_pwd($this->conn);
        if (@ftp_chdir($this->conn, $path)) {
            ftp_chdir($this->conn, $originalDirectory);
            return true;
        } else {
            return false;
        }
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

}