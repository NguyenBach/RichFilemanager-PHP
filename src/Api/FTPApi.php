<?php


namespace RFM\Api;


use RFM\Event\Api\AfterFolderCreateEvent;
use RFM\Event\Api\AfterFolderReadEvent;
use RFM\Event\Api\AfterFolderSeekEvent;
use RFM\Event\Api\AfterItemCopyEvent;
use RFM\Event\Api\AfterItemMoveEvent;
use RFM\Event\Api\AfterItemRenameEvent;
use RFM\Facade\Input;
use RFM\Facade\Log;
use RFM\Repository\BaseStorage;
use RFM\Repository\FTP\ItemModel;
use RFM\Repository\FTP\Storage;
use function RFM\app;
use function RFM\dispatcher;

class FTPApi implements ApiInterface
{
    /**
     * @var Storage
     */
    protected $storage;

    /**
     * FTPApi constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        $this->storage = app()->getStorage(BaseStorage::STORAGE_FTP_NAME);
    }

    public function actionInitiate()
    {
        $shared_config = [
            'security' => [
                'readOnly' => $this->storage->config('security.readOnly'),
                'extensions' => [
                    'policy' => $this->storage->config('security.extensions.policy'),
                    'ignoreCase' => $this->storage->config('security.extensions.ignoreCase'),
                    'restrictions' => $this->storage->config('security.extensions.restrictions'),
                ],
            ],
            'upload' => [
                'fileSizeLimit' => $this->storage->config('upload.fileSizeLimit'),
                'paramName' => $this->storage->config('upload.paramName'),
            ],
            'viewer' => [
                'absolutePath' => $this->storage->config('viewer.absolutePath'),
                'previewUrl' => $this->storage->config('viewer.previewUrl'),
            ],
        ];

        return [
            'id' => '/',
            'type' => 'initiate',
            'attributes' => [
                'config' => $shared_config,
            ],
        ];
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function actionGetInfo()
    {
        $model = new ItemModel(Input::get('path'));

        $model->checkPath();
        $model->checkReadPermission();

        return $model->getData()->formatJsonApi();
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function actionReadFolder()
    {
        $filesPaths = [];
        $responseData = [];
        $model = new ItemModel(Input::get('path'));
        $model->checkPath();
        $model->checkReadPermission();
        if (!$model->isDirectory()) {
            app()->error('DIRECTORY_NOT_EXIST', [$model->getRelativePath()]);
        }
        try {
            $filesList = $this->storage->listFiles($model->getAbsolutePath());
            if ($filesList) {
                foreach ($filesList as $file) {
                    if ($file == '.' || $file == '..') {
                        continue;
                    }
                    $item = new ItemModel($file);
                    if ($item->isUnrestricted()) {
                        $filesPaths[] = $item->getAbsolutePath();
                        $responseData[] = $item->getData()->formatJsonApi();
                    }
                }
            }
        } catch (\Exception $exception) {
            app()->error('UNABLE_TO_OPEN_DIRECTORY', [$model->getRelativePath()]);
        }


        // create event and dispatch it
        $event = new AfterFolderReadEvent($model->getData(), $filesPaths);
        dispatcher()->dispatch($event::NAME, $event);

        return $responseData;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function actionSeekFolder()
    {
        $searchString = Input::get('string');
        $model = new ItemModel(Input::get('path'));

        $model->checkPath();
        $model->checkReadPermission();

        $filesPaths = [];
        $responseData = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($model->getAbsolutePath()),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $spl) {
            $filename = $spl->getFilename();
            $pathname = $spl->getPathname();

            if ($this->storage->compareFilename($filename, $searchString)) {
                // directory path must end with slash
                $pathname .= $spl->isDir() ? '/' : '';

                $relativePath = $this->storage->getRelativePath($pathname);
                $item = new ItemModel($relativePath);
                if ($item->isUnrestricted()) {
                    $filesPaths[] = $item->getAbsolutePath();
                    $responseData[] = $item->getData()->formatJsonApi();
                }
            }
        }

        // create event and dispatch it
        $event = new AfterFolderSeekEvent($model->getData(), $searchString, $filesPaths);
        dispatcher()->dispatch($event::NAME, $event);

        return $responseData;
    }

    public function actionSaveFile()
    {
        // TODO: Implement actionSaveFile() method.
    }

    /**
     * @return array|bool
     * @throws \Exception
     */
    public function actionRename()
    {
        $modelOld = new ItemModel(Input::get('old'));
        $filename = Input::get('new');
        // forbid to change path during rename
        if (strrpos($filename, '/') !== false) {
            app()->error('FORBIDDEN_CHAR_SLASH');
        }

        // check if not requesting root storage folder
        if ($modelOld->isDirectory() && $modelOld->isRoot()) {
            app()->error('NOT_ALLOWED');
        }
        try {
            $newPath = $this->storage->rename($modelOld->getAbsolutePath(), $filename);
            $modelNew = new ItemModel($newPath);
            $event = new AfterItemRenameEvent($modelNew->getData(), $modelOld->getData());
            dispatcher()->dispatch($event::NAME, $event);

            return $modelNew->compileData()->formatJsonApi();
        } catch (\Exception $exception) {
            app()->error($exception->getMessage());
            return false;
        }

    }

    /**
     * @return array
     * @throws \Exception
     */
    public function actionCopy()
    {
        $modelSource = new ItemModel(Input::get('source'));
        $modelTarget = new ItemModel(Input::get('target'));

        $suffix = $modelSource->isDirectory() ? '/' : '';
        $extension = pathinfo($modelSource->getAbsolutePath(), PATHINFO_EXTENSION);
        if (!$extension) {
            $extension = '';
        } else {
            $extension = '.' . $extension;
        }
        $basename = pathinfo($modelSource->getAbsolutePath(), PATHINFO_FILENAME) . "_copy_" . time() . $extension;
        $modelNew = new ItemModel($modelTarget->getRelativePath() . '/' . $basename . $suffix);

        if (!$modelTarget->isDirectory()) {
            app()->error('DIRECTORY_NOT_EXIST', [$modelTarget->getRelativePath()]);
        }

        if ($modelSource->isDirectory() && $modelSource->isRoot()) {
            app()->error('NOT_ALLOWED');
        }

        // check items permissions
        $modelSource->checkPath();
        $modelSource->checkReadPermission();
        $modelTarget->checkPath();
        $modelTarget->checkWritePermission();

        // check if file already exists
        if ($modelNew->isExists()) {
            if ($modelNew->isDirectory()) {
                app()->error('DIRECTORY_ALREADY_EXISTS', [$modelNew->getRelativePath()]);
            } else {
                app()->error('FILE_ALREADY_EXISTS', [$modelNew->getRelativePath()]);
            }
        }

        // copy file or folder
        if ($modelSource->isDirectory()) {
            $copied = $this->storage->copyFolder($modelSource->getAbsolutePath(), $modelNew->getAbsolutePath());
            if (!$copied) {
                app()->error('ERROR_COPYING_DIRECTORY', [$basename, $modelTarget->getRelativePath()]);
            }
        } else {
            try {
                $copied = $this->storage->copyFile($modelSource->getAbsolutePath(), $modelNew->getAbsolutePath());
                if (!$copied) {
                    app()->error('ERROR_COPYING_FILE', [$basename, $modelTarget->getRelativePath()]);
                }
            } catch (\Exception $exception) {
                app()->error('ERROR_COPYING_FILE', [$exception->getMessage()]);

            }
        }
        // create event and dispatch it
        $event = new AfterItemCopyEvent($modelNew->getData(), $modelSource->getData());
        dispatcher()->dispatch($event::NAME, $event);

        return $modelNew->compileData()->formatJsonApi();
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function actionMove()
    {
        $modelSource = new ItemModel(Input::get('old'));

        $modelTarget = new ItemModel(Input::get('new'));
        $suffix = $modelSource->isDirectory() ? '/' : '';
        $basename = basename($modelSource->getAbsolutePath());

        $modelNew = new ItemModel($modelTarget->getRelativePath() . '/' . $basename . $suffix);
        if (!$modelTarget->isDirectory()) {
            app()->error('DIRECTORY_NOT_EXIST', [$modelTarget->getRelativePath()]);
        }

        // check if not requesting root storage folder
        if ($modelSource->isDirectory() && $modelSource->isRoot()) {
            app()->error('NOT_ALLOWED');
        }
        // check items permissions
        $modelSource->checkPath();
        $modelSource->checkWritePermission();
        $modelTarget->checkPath();
        $modelTarget->checkWritePermission();

        // check if file already exists
        if ($modelNew->isExists()) {
            if ($modelNew->isDirectory()) {
                app()->error('DIRECTORY_ALREADY_EXISTS', [$modelNew->getRelativePath()]);
            } else {
                app()->error('FILE_ALREADY_EXISTS', [$modelNew->getRelativePath()]);
            }
        }

        try {
            $moved = $this->storage->rename($modelSource->getAbsolutePath(), $modelNew->getAbsolutePath());
            if (!$moved) {
                if ($modelSource->isDirectory()) {
                    app()->error('ERROR_MOVING_DIRECTORY', [$basename, $modelTarget->getRelativePath()]);
                } else {
                    app()->error('ERROR_MOVING_FILE', [$basename, $modelTarget->getRelativePath()]);
                }
            }
        } catch (\Exception $exception) {
            app()->error($exception->getMessage());
        }


        // create event and dispatch it
        $event = new AfterItemMoveEvent($modelNew->getData(), $modelSource->getData());
        dispatcher()->dispatch($event::NAME, $event);

        return $modelNew->compileData()->formatJsonApi();
    }

    public function actionDelete()
    {
        // TODO: Implement actionDelete() method.
    }

    public function actionUpload()
    {
        // TODO: Implement actionUpload() method.
    }

    public function actionAddFolder()
    {
        $targetPath = Input::get('path');
        $targetName = Input::get('name');

        $modelTarget = new ItemModel($targetPath);
        $modelTarget->checkPath();
        $modelTarget->checkWritePermission();

        $dirName = $this->storage->normalizeString(trim($targetName, '/')) . '/';
        $relativePath = $this->storage->cleanPath('/' . $targetPath . '/' . $dirName);

        $model = new ItemModel($relativePath);

        if ($model->isExists() && $model->isDirectory()) {
            app()->error('DIRECTORY_ALREADY_EXISTS', [$targetName]);
        }
        try {
            $created = $this->storage->createFolder($model, $modelTarget);
            if (!$created) {
                app()->error('UNABLE_TO_CREATE_DIRECTORY', [$targetName]);
            }
        } catch (\Exception $exception) {
            app()->error($exception->getMessage());
        }

        // create event and dispatch it
        $event = new AfterFolderCreateEvent($model->getData());
        dispatcher()->dispatch($event::NAME, $event);

        return $model->compileData()->formatJsonApi();
    }

    public function actionDownload()
    {
        // TODO: Implement actionDownload() method.
    }

    /**
     * @param bool $thumbnail
     * @throws \Exception
     */
    public function actionGetImage($thumbnail)
    {
        $modelImage = new ItemModel(Input::get('path'));
        if ($modelImage->isDirectory()) {
            app()->error('FORBIDDEN_ACTION_DIR');
        }

        $modelImage->checkReadPermission();
        $mimeType = $this->storage->getMimeType($modelImage->getAbsolutePath());
        header("Content-Type: {$mimeType}");
        header("Content-Length: " . $this->storage->getFileSize($modelImage->getAbsolutePath()));
        $ouput = fopen('php://output', 'r+');
        try {
            $this->storage->readFile($modelImage->getAbsolutePath(), $ouput);
        } catch (\Exception $exception) {
            dd($exception->getMessage());
        }

        exit;
    }

    public function actionReadFile()
    {
        // TODO: Implement actionReadFile() method.
    }

    public function actionSummarize()
    {
        // TODO: Implement actionSummarize() method.
    }

    public function actionExtract()
    {
        // TODO: Implement actionExtract() method.
    }
}