<?php


namespace RFM\Api;


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
        $filesList = [];
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
        $event = new ApiEvent\AfterFolderReadEvent($model->getData(), $filesPaths);
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
        $event = new ApiEvent\AfterFolderSeekEvent($model->getData(), $searchString, $filesPaths);
        dispatcher()->dispatch($event::NAME, $event);

        return $responseData;
    }

    public function actionSaveFile()
    {
        // TODO: Implement actionSaveFile() method.
    }

    public function actionRename()
    {
        // TODO: Implement actionRename() method.
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

    public function actionGetImage($thumbnail)
    {
        // TODO: Implement actionGetImage() method.
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