<?php


namespace RFM\Api;


use RFM\Event\Api\AfterFolderReadEvent;
use RFM\Event\Api\AfterFolderSeekEvent;
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

        if (!$filesList = $this->storage->listFiles($model->getAbsolutePath())) {
            app()->error('UNABLE_TO_OPEN_DIRECTORY', [$model->getRelativePath()]);
        } else {
            foreach ($filesList as $file) {
                $item = new ItemModel($file);
                if ($item->isUnrestricted()) {
                    $filesPaths[] = $item->getAbsolutePath();
                    $responseData[] = $item->getData()->formatJsonApi();
                }
            }
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
     * @return array
     * @throws \Exception
     */
    public function actionRename()
    {
        $modelOld = new ItemModel(Input::get('old'));
        $suffix = $modelOld->isDirectory() ? '/' : '';
        $filename = Input::get('new');

        // forbid to change path during rename
        if (strrpos($filename, '/') !== false) {
            app()->error('FORBIDDEN_CHAR_SLASH');
        }

        // check if not requesting root storage folder
        if ($modelOld->isDirectory() && $modelOld->isRoot()) {
            app()->error('NOT_ALLOWED');
        }


        $newPath = $this->storage->rename($modelOld->getAbsolutePath(), $filename);

        $modelNew = new ItemModel($newPath);

        // create event and dispatch it
        $event = new AfterItemRenameEvent($modelNew->getData(), $modelOld->getData());
        dispatcher()->dispatch($event::NAME, $event);

        return $modelNew->compileData()->formatJsonApi();
    }

    public function actionCopy()
    {
        // TODO: Implement actionCopy() method.
    }

    public function actionMove()
    {
        // TODO: Implement actionMove() method.
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
        // TODO: Implement actionAddFolder() method.
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
        $this->storage->readFile($modelImage->getAbsolutePath(), $ouput);
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