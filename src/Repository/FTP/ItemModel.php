<?php
/**
 * Class ItemModel
 * Item is file or directory, this is model to save all info about file
 */

namespace RFM\Repository\FTP;


use RFM\Repository\BaseItemModel;
use RFM\Repository\BaseStorage;
use RFM\Repository\ItemData;
use RFM\Repository\ItemModelInterface;
use function RFM\app;

class ItemModel extends BaseItemModel implements ItemModelInterface
{

    /**
     * @var Storage
     */
    protected $storage;

    private $relativePath;

    private $absolutePath;

    private $isDir;

    private $isExists;

    private $permission;

    private $parent;

    /**
     * ItemModel constructor.
     * @param $path
     * @throws \Exception
     */
    public function __construct($path)
    {
        $this->setStorage(BaseStorage::STORAGE_FTP_NAME);
        $this->relativePath = $this->storage->getRelativePath($path);
        $this->absolutePath = $this->storage->getAbsolutePath($path);
        $this->permission = $this->storage->getPermission($this->absolutePath);
        $this->isExists = $this->storage->isFileExists($this->absolutePath);

    }

    public function getRelativePath()
    {
        return $this->relativePath;
    }

    public function getAbsolutePath()
    {
        return $this->absolutePath;
    }

    public function getDynamicPath()
    {
        return $this->absolutePath;
    }

    public function getPermission()
    {
        return $this->permission;
    }

    public function getThumbnailPath()
    {
        $path = '/' . $this->storage->config('images.thumbnail.dir') . '/' . $this->relativePath;
        return $this->storage->cleanPath($path);
    }

    public function getOriginalPath()
    {
        $info = pathinfo($this->relativePath);
        return $info['basename'];
    }

    public function isDirectory()
    {
        return $this->storage->isDir($this->relativePath);
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function isExists()
    {
        return $this->isExists;
    }

    public function isRoot()
    {
        return $this->absolutePath === $this->storage->getRoot();
    }

    public function isImageFile()
    {
        $pathInfo = pathinfo($this->absolutePath);
        if (!isset($pathInfo['extension'])) {
            return false;
        }
        return in_array(strtolower($pathInfo['extension']), $this->imageExtension());
    }

    public function isValidPath()
    {
        return true;
    }

    /**
     * Check the extensions blacklist for item.
     *
     * @return bool
     */
    public function isAllowedExtension()
    {
        // check the extension (for files):
        $extension = pathinfo($this->relativePath, PATHINFO_EXTENSION);
        $extensionRestrictions = $this->storage->config('security.extensions.restrictions');

        if ($this->storage->config('security.extensions.ignoreCase')) {
            $extension = strtolower($extension);
            $extensionRestrictions = array_map('strtolower', $extensionRestrictions);
        }

        if ($this->storage->config('security.extensions.policy') === 'ALLOW_LIST') {
            if (!in_array($extension, $extensionRestrictions)) {
                // Not in the allowed list, so it's restricted.
                return false;
            }
        } else if ($this->storage->config('security.extensions.policy') === 'DISALLOW_LIST') {
            if (in_array($extension, $extensionRestrictions)) {
                // It's in the disallowed list, so it's restricted.
                return false;
            }
        } else {
            // Invalid config option for 'policy'. Deny everything for safety.
            return false;
        }

        // Nothing restricted this path, so it is allowed.
        return true;
    }

    public function isAllowedPattern()
    {
        $pathRelative = $this->getOriginalPath();
        $patternRestrictions = $this->storage->config('security.patterns.restrictions');

        if ($this->storage->config('security.patterns.ignoreCase')) {
            $pathRelative = strtolower($pathRelative);
            $patternRestrictions = array_map('strtolower', $patternRestrictions);
        }

        // (check for a match before applying the restriction logic)
        $matchFound = false;
        foreach ($patternRestrictions as $pattern) {
            if (fnmatch($pattern, $pathRelative)) {
                $matchFound = true;
                break;  // Done.
            }
        }

        if ($this->storage->config('security.patterns.policy') === 'ALLOW_LIST') {
            if (!$matchFound) {
                // relative path did not match the allowed pattern list, so it's restricted:
                return false;
            }
        } else if ($this->storage->config('security.patterns.policy') === 'DISALLOW_LIST') {
            if ($matchFound) {
                // relative path matched the disallowed pattern list, so it's restricted:
                return false;
            }
        } else {
            // Invalid config option for 'policy'. Deny everything for safety.
            return false;
        }

        // Nothing is restricting access to this item, so it is allowed.
        return true;
    }

    public function isUnrestricted()
    {
        $valid = true;
        if (!$this->isDir) {
            $valid = $valid && $this->isAllowedExtension();
        }
        return $valid && $this->isAllowedPattern();
    }

    public function hasReadPermission()
    {
        return $this->permission['read'];
    }

    public function hasWritePermission()
    {
        return $this->permission['write'];
    }

    public function checkPath()
    {
        if (!$this->isExists) {
            $langKey = $this->isDir ? 'DIRECTORY_NOT_EXIST' : 'FILE_DOES_NOT_EXIST';
            app()->error($langKey, [$this->relativePath]);
        }

        if (!$this->isValidPath()) {
            $langKey = $this->isDir ? 'INVALID_DIRECTORY_PATH' : 'INVALID_FILE_PATH';
            app()->error($langKey, [$this->relativePath]);
        }
        return true;
    }

    public function checkReadPermission()
    {
        if ($this->permission['read'] === false) {
            app()->error('NOT_ALLOWED_SYSTEM');
        }

        // Check the user's Auth API callback:
        if (function_exists('fm_has_read_permission') && fm_has_read_permission($this->absolutePath) === false) {
            app()->error('NOT_ALLOWED');
        }

        // Nothing is restricting access to this file or dir, so it is readable
        return;
    }

    public function checkWritePermission()
    {
        if ($this->permission['write'] === false) {
            app()->error('NOT_ALLOWED_SYSTEM');
        }

        // Check the user's Auth API callback:
        if (function_exists('fm_has_write_permission') && fm_has_write_permission($this->absolutePath) === false) {
            app()->error('NOT_ALLOWED');
        }

        // Nothing is restricting access to this file or dir, so it is readable
        return;
    }

    /**
     * @return ItemData
     * @throws \Exception
     */
    public function getData()
    {
        return $this->compileData();
    }

    /**
     * @return ItemModel|ItemModelInterface|null
     * @throws \Exception
     */
    public function closest()
    {
        if (is_null($this->parent)) {
            // dirname() trims trailing slash
            $path = dirname($this->relativePath) . '/';
            // root folder returned as backslash for Windows
            $path = $this->storage->cleanPath($path);

            // can't get parent
            if ($this->isRoot()) {
                return null;
            }
            $this->parent = new self($path);
        }

        return $this->parent;
    }

    public function thumbnail()
    {
        // TODO: Implement thumbnail() method.
    }

    public function createThumbnail()
    {
        // TODO: Implement createThumbnail() method.
    }

    public function remove()
    {
        // TODO: Implement remove() method.
    }

    private function imageExtension()
    {
        return [
            'jpg', 'png', 'jpeg', 'gif'
        ];
    }

    /**
     * @return ItemData
     * @throws \Exception
     */
    private function compileData()
    {
        $data = new ItemData();
        $data->pathRelative = $this->relativePath;
        $data->pathAbsolute = $this->absolutePath;
        $data->pathDynamic = $this->getDynamicPath();
        $data->isDirectory = $this->isDir;
        $data->isExists = $this->isExists;
        $data->isRoot = $this->isRoot();
        $data->isImage = $this->isImageFile();
        $data->timeModified = $this->isDir ? null : $this->storage->getModifyTime($this->absolutePath, 'Y-m-d');
        $data->timeCreated = $data->timeModified;

        // check file permissions
        $data->isReadable = $this->hasReadPermission();
        $data->isWritable = $this->hasWritePermission();

        // fetch file info
        $pathInfo = pathinfo($this->absolutePath);
        $data->basename = $pathInfo['basename'];

        // get file size
        if (!$this->isDir && $data->isReadable) {
            $data->size = $this->storage->getFileSize($this->absolutePath);
        }

        // handle image data
        if ($data->isImage) {
            $data->imageData['isThumbnail'] = true;
            $data->imageData['pathOriginal'] = $this->getOriginalPath();
            $data->imageData['pathThumbnail'] = $this->getThumbnailPath();

            if ($data->isReadable && $data->size > 0) {
                list($width, $height, $type, $attr) = getimagesize($this->absolutePath);
            } else {
                list($width, $height) = [0, 0];
            }

            $data->imageData['width'] = $width;
            $data->imageData['height'] = $height;
        }

        return $data;
    }

}