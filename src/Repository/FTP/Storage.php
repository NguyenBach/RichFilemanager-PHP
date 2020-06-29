<?php
/**
 * Class Storage
 * Các phương thức để kết nối đến ftp
 */

namespace RFM\Repository\FTP;


use RFM\Repository\BaseStorage;
use RFM\Repository\ItemModelInterface;
use RFM\Repository\StorageInterface;
use function RFM\app;
use function RFM\mime_type_by_extension;

class Storage extends BaseStorage implements StorageInterface
{
    /**
     * Kết nối FTP
     * @var FTP
     */
    private $ftp;

    /**
     * Thư mục gốc của ftp
     * @var string
     */
    protected $rootDir;


    /**
     * Storage constructor.
     * @param array $config
     * @throws \Exception
     */
    public function __construct($config = [])
    {
        $this->setName(BaseStorage::STORAGE_FTP_NAME);
        $this->setConfig($config);
        $this->initFTP();
    }

    /**
     * @throws \Exception
     */
    public function initFTP()
    {
        if (!$this->config('ftp')) {
            throw new \Exception("FTP connection info isn't set");
        }
        $host = $this->config('ftp.host');
        $port = $this->config('ftp.port', '21');
        $timeout = $this->config('ftp.timeout', 90);
        $username = $this->config('ftp.username', '');
        $password = $this->config('ftp.password', '');
        $this->ftp = new FTP($host, $username, $password, $port, $timeout);
        try {
            $this->ftp->connect();
        } catch (\Exception $e) {
            app()->error($e->getMessage());
        }
    }

    public function cleanPath($string, $removeParent = false)
    {
        $string = rtrim($string, '/');
        // replace backslashes (windows separators)
        $string = str_replace("\\", "/", $string);
        // remove multiple slashes
        $string = preg_replace('#/+#', '/', $string);
        if ($removeParent) {
            $pathInfo = pathinfo($string);
            $string = '/' . $pathInfo['filename'];
        }
        return $string;
    }

    public function setRoot($path, $makeDir = false)
    {
        $this->rootDir = $path;
    }

    public function getRoot()
    {
        return $this->rootDir;
    }

    public function getDynamicRoot()
    {
        return $this->rootDir;
    }

    public function getRelativePath($path)
    {
        $position = strrpos($path, $this->rootDir);
        if ($position === 0) {
            $path = substr($path, strlen($this->rootDir));
            return $path ? $this->cleanPath('/' . $path) : '';
        }
        return $path;
    }

    public function getAbsolutePath($path)
    {
        $position = strrpos($path, $this->rootDir);
        if ($position === 0) {
            return $path;
        }
        return $this->cleanPath($this->rootDir . '/' . $path);
    }

    /**
     * @param ItemModelInterface $target
     * @param string $prototype
     * @param string $options
     * @return bool|string
     * @throws \Exception
     */
    public function createFolder($target, $prototype = '', $options = '')
    {
        return $this->ftp->mkdir($target->getAbsolutePath());
    }

    public function getMimeType($path)
    {
        return mime_type_by_extension($path);
    }

    /**
     * @param string $path
     * @return false|int|string
     * @throws \Exception
     */
    public function getFileSize($path)
    {
        return $this->ftp->fileSize($path);
    }

    public function getDirSummary($dir, &$result)
    {
        // TODO: Implement getDirSummary() method.
    }

    public function isDir($path)
    {
        if ($path == $this->rootDir) {
            return true;
        }
        $pathInfo = pathinfo($path);
        return !isset($pathInfo['extension']);
    }

    /**
     * @param $path
     * @return array
     * @throws \Exception
     */
    public function getPermission($path)
    {
        if ($path == $this->rootDir) {
            return $permission = [
                'read' => true,
                'write' => true,
                'execute' => true
            ];
        }
        $filePath = pathinfo($path);
        $fileList = $this->ftp->rawListFiles($filePath['dirname']);
        if ($fileList === false) {
            app()->error('ERROR', ["Cannot open file list"]);
        }
        if (isset($filePath['extension'])) {
            $fileWithExtension = $filePath['filename'] . '.' . $filePath['extension'];
        } else {
            $fileWithExtension = $filePath['filename'];
        }
        $permission = $this->getPermission($filePath['dirname']);
        foreach ($fileList as $v) {
            $vinfo = preg_split("/[\s]+/", $v, 9);
            if ($vinfo[0] !== "total") {
                if ($vinfo[8] === $fileWithExtension) {
                    $chmod = $vinfo[0];
                    if ($chmod[1] == 'r' || $chmod[4] == 'r' || $chmod[7] == 'r') {
                        $permission['read'] = true;
                    }
                    if ($chmod[2] == 'w' || $chmod[5] == 'w' || $chmod[8] == 'w') {
                        $permission['write'] = true;
                    }
                    if ($chmod[3] == 'x' || $chmod[6] == 'x' || $chmod[9] == 'x') {
                        $permission['execute'] = true;
                    }
                    break;
                }
            }
        }
        return $permission;
    }

    /**
     * @param $path
     * @return bool
     * @throws \Exception
     */

    public function isFileExists($path)
    {
        if ($this->rootDir == $path) {
            return true;
        }
        $pathInfo = pathinfo($path);
        $listFiles = $this->ftp->listFiles($pathInfo['dirname']);
        $path = $this->cleanPath($path);
        return in_array($path, $listFiles);
    }

    /**
     * @param $path
     * @param string $format
     * @return int
     * @throws \Exception
     */
    public function getModifyTime($path, $format = 'number')
    {
        $date = $this->ftp->getModifyTime($path);
        if ($format === 'number') {
            return $date;
        }
        return date($format, $date);
    }

    /**
     * @param $path
     * @return array|false
     * @throws \Exception
     */
    public function listFiles($path)
    {
        return $this->ftp->listFiles($path);
    }

    public function getFtpUrl($path)
    {
        return $this->ftp->ftpUrl() . $path;
    }

    public function readFile($path, $output)
    {
        $this->ftp->readFile($path, $output);
    }

    public function rename($path, $newName)
    {
        return $this->ftp->rename($path, $newName);
    }

    public function copyFile($currentPath, $copyPath)
    {
        $filename = pathinfo($currentPath, PATHINFO_BASENAME);
        return $this->ftp->copyFile($currentPath, $copyPath, $filename);
    }

    public function copyFolder($currentPath, $copyFolder)
    {
        return $this->ftp->copyFolder($currentPath, $copyFolder);
    }
}