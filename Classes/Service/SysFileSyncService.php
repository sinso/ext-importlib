<?php
namespace Sinso\Importlib\Service;

class SysFileSyncService {

    /**
     * @var \Sinso\Importlib\Service\SimpleSyncService
     */
    protected $simpleSyncService;

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var string
     */
    protected $resourcePath;

    /**
     * SysFileSyncService constructor.
     * @param string $importName
     * @param string $tableName
     * @param string $resourcePath
     */
    public function __construct($importName, $tableName, $resourcePath) {
        $this->simpleSyncService = new SimpleSyncService($importName, 'sys_file_reference');
        $this->tableName = $tableName;
        $this->resourcePath = $resourcePath;
    }

    public function initializeResource($recordUid, $resourceUrl, $syncStrategy = SimpleSyncService::SYNC_PREFER_SOURCE) {
        $resourceUid = $this->syncPhysicalResource($resourceUrl, $syncStrategy);
        if ($resourceUid) {
            $whereFields = array(
                'uid_local' => $resourceUid,
                'uid_foreign' => $recordUid,
                'tablenames' => $this->tableName,
                'fieldname' => 'image',
                'table_local' => 'sys_file'
                );
            $this->simpleSyncService->initializeRow($whereFields);
        }
    }

    public function insertUpdateRow() {
        return $this->simpleSyncService->insertUpdateRow();
    }

    public function syncField($fieldName, $sourceValue, $syncStrategy = SimpleSyncService::SYNC_PREFER_SOURCE) {
        $this->simpleSyncService->syncField($fieldName, $sourceValue, $syncStrategy);
    }

    public function deleteAbsentRows() {
        return $this->simpleSyncService->deleteAbsentRows();
    }

    /**
     * @param string $url
     * @param int $syncStrategy
     * @return bool|int
     */
    public function syncPhysicalResource($url, $syncStrategy = SimpleSyncService::SYNC_PREFER_SOURCE) {
        $localName = \TYPO3\CMS\Core\Utility\PathUtility::basename($url);
        $fullLocalPath = $this->resourcePath . $localName;
        $targetResourceExists = file_exists($fullLocalPath);
        $targetResourceHasChanged = FALSE; //TODO: detect changes necessary for imported files?
        $sourceResourceHasChanged = TRUE; //TODO: detect changes (ETAG, CURLOPT_NOBODY+CURLOPT_FILETIME)

        $syncResource = FALSE;
        if ($targetResourceExists && !$sourceResourceHasChanged && !$targetResourceHasChanged) {
            $syncResource = FALSE;
        }

        if ($syncStrategy === SimpleSyncService::SYNC_PREFER_TARGET && $targetResourceExists && $targetResourceHasChanged) {
            $syncResource = FALSE;
        }
        //TODO: what if file was deleted intentionally and import should not create it again?

        if ($syncResource) {
            $fileData = \TYPO3\CMS\Core\Utility\GeneralUtility::getURL($url);
            if (! $fileData) {
                return FALSE;
            }

            if (!\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($fullLocalPath, $fileData)) {
                return FALSE;
            }
        }
        try {
            $fileObject = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->retrieveFileOrFolderObject($fullLocalPath);
            return $fileObject->getUid();
        } catch (\Exception $e) {
            return FALSE;
        }
    }
}
