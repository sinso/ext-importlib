<?php
namespace Sinso\Importlib\Service;

use TYPO3\CMS\Core\Log\LogLevel;

class SysFileSyncService {

    /**
     * @var \Sinso\Importlib\Service\SimpleSyncService
     */
    protected $simpleSyncService;

    /**
     * @var string
     */
    protected $importName;

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var string
     */
    protected $resourcePath;

    /**
     * @var int
     */
    protected $resourceUid;

    /**
     * @var \TYPO3\CMS\Core\Log\Logger
     */
    protected $logger;

    /**
     * SysFileSyncService constructor.
     * @param string $importName
     * @param string $tableName
     * @param string $resourcePath
     */
    public function __construct($importName, $tableName, $resourcePath) {
        $this->simpleSyncService = new SimpleSyncService($importName, 'sys_file_reference');
        $this->importName = $importName;
        $this->tableName = $tableName;
        $this->resourcePath = $resourcePath;
        $this->logger = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\CMS\Core\Log\LogManager')->getLogger(__CLASS__);
    }

    public function initializeResource($recordUid, $resourceUrl, $syncStrategy = SimpleSyncService::SYNC_PREFER_SOURCE) {
        if ($this->resourceUid = $this->syncPhysicalResource($resourceUrl, $syncStrategy)) {
            $whereFields = array(
                'uid_local' => $this->resourceUid,
                'uid_foreign' => $recordUid,
                'tablenames' => $this->tableName,
                'fieldname' => 'image',
                'table_local' => 'sys_file'
                );
            $this->simpleSyncService->initializeRow($whereFields);
        }
    }

    public function insertUpdateRow() {
        if ($this->resourceUid) {
            return $this->simpleSyncService->insertUpdateRow();
        }
        return 0;
    }

    public function syncField($fieldName, $sourceValue, $syncStrategy = SimpleSyncService::SYNC_PREFER_SOURCE) {
        if ($this->resourceUid) {
            $this->simpleSyncService->syncField($fieldName, $sourceValue, $syncStrategy);
        }
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
        $uid = 0;
        $localName = \TYPO3\CMS\Core\Utility\PathUtility::basename($url);
        $fullLocalPath = $this->resourcePath . $localName;
        $syncResource = TRUE;

        if (file_exists($fullLocalPath)) {
            $syncResource = FALSE;
            /* TODO: Detect changes and sync resources according the logic below
            $targetResourceHasChanged = FALSE; //TODO: detect changes necessary for imported files?
            $sourceResourceHasChanged = TRUE; //TODO: detect changes (ETAG, CURLOPT_NOBODY+CURLOPT_FILETIME)
            if ($sourceResourceHasChanged) { // Change on source
                if ($targetResourceHasChanged) { // Change on target -> conflict
                    if ($syncStrategy === SimpleSyncService::SYNC_PREFER_TARGET) { // No change
                        $syncResource = FALSE;
                    }
                }
            }*/
        }

        if ($syncResource) {
            if ($fileData = \TYPO3\CMS\Core\Utility\GeneralUtility::getURL($url)) {
                $this->logger->log(LogLevel::INFO, 'Sync Resource: Loaded file from URL', $this->getLogData());
                if (\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($fullLocalPath, $fileData)) {
                    $this->logger->log(LogLevel::INFO, 'Sync Resource: Wrote file to local', $this->getLogData());
                } else {
                    $this->logger->log(LogLevel::ERROR, 'Sync Resource: Write file to local FAILED', $this->getLogData());
                }
            } else {
                $this->logger->log(LogLevel::ERROR, 'Sync Resource: Load file from URL FAILED', $this->getLogData());
            }
        }

        if ($fileObject = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->retrieveFileOrFolderObject($fullLocalPath)) {
            $uid = $fileObject->getUid();
            $this->logger->log(LogLevel::INFO, 'Sync Resource: Read file from local', $this->getLogData());
        } else  {
            $this->logger->log(LogLevel::ERROR, 'Sync Resource: Read file from local FAILED', $this->getLogData());
        }
        return $uid;
    }

    private function getBaseLogData() {
        return array('importName' => $this->importName, 'tableName' => $this->tableName);
    }

    private function getLogData() {
        $additionalLogData = $this->getBaseLogData();
        if (! empty($this->resourceUid)) {
            $additionalLogData['resourceUid'] = $this->resourceUid;
        }
        return $additionalLogData;
    }
}
