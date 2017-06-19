<?php
namespace Sinso\Importlib\Service;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2017 Helfer Dominique <dominique.helfer@swisscom.com>
 *  (c) 2017 Simon Gadient <simon.gadient@swisscom.com>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Class SysFileSyncService
 *
 * TODO Change this to a decorator of SimpleSyncService
 *
 * @package Sinso\Importlib\Service
 */
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

    /**
     * Initialize a new resource to import. Loads the resource and initializes the related data row.
     *
     * @param int $recordUid
     * @param string $resourceUrl
     * @param int $syncStrategy
     */
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

    /**
     * Inserts or updates the related data row if the resource exists.
     *
     * @return int
     */
    public function insertUpdateRow() {
        if ($this->resourceUid) {
            return $this->simpleSyncService->insertUpdateRow();
        }
        return 0;
    }

    /**
     * Syncs a specific field if the resource exists.
     *
     * @param string $fieldName
     * @param mixed $sourceValue
     * @param int $syncStrategy
     */
    public function syncField($fieldName, $sourceValue, $syncStrategy = SimpleSyncService::SYNC_PREFER_SOURCE) {
        if ($this->resourceUid) {
            $this->simpleSyncService->syncField($fieldName, $sourceValue, $syncStrategy);
        }
    }

    /**
     * Deletes previously imported rows which are not present in the current import anymore. Only the delete flag is
     * updated and therefore resource files must not be physically deleted.
     *
     * @param bool $deletePhysicalResources
     *
     * @return array
     */
    public function deleteAbsentRows($deletePhysicalResources = FALSE) {
        $deleteUids = $this->simpleSyncService->getAbsentRowsToDelete();

        if ($deletePhysicalResources) {
            $deletedFileUids = array();

            foreach ($deleteUids as $uid) {
                $fileReferenceRow = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('*', 'sys_file_reference', "uid=$uid");
                $fileUid = (int)($fileReferenceRow['uid_local'] ?? 0);

                if ($fileUid === 0 || in_array($fileUid, $deletedFileUids)) {
                    continue;
                }

                $referencesToThisFile = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('uid', 'sys_file_reference', "uid_local=$fileUid and deleted=0");
                $referencesToThisFileCount = count($referencesToThisFile);

                $canDeleteFile = $referencesToThisFileCount === 1;
                if (!$canDeleteFile) {
                    $count = 0;
                    foreach ($referencesToThisFile as $referenceToThisFile) {
                        if (in_array($referenceToThisFile['uid'], $deleteUids)) {
                            $count++;
                        }
                    }
                    if ($count === $referencesToThisFileCount) {
                        $canDeleteFile = TRUE;
                    }
                }

                if ($canDeleteFile) {
                    try {
                        $resourceFactory = ResourceFactory::getInstance();
                        $fileReference = $resourceFactory->getFileReferenceObject($uid, $fileReferenceRow);
                        $deleted = $fileReference->getOriginalFile()->delete();
                        $deletedFileUids[] = $fileUid;
                    } catch (\Exception $e) {}
                }
            }
        }

        return $this->simpleSyncService->deleteAbsentRows($deleteUids);
    }

    /**
     * Sync a specific resource.
     *
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
                $this->logger->log(LogLevel::DEBUG, 'Sync Resource: Loaded file from URL', $this->getLogData());
                if (\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($fullLocalPath, $fileData)) {
                    $this->logger->log(LogLevel::DEBUG, 'Sync Resource: Wrote file to local', $this->getLogData());
                } else {
                    $this->logger->log(LogLevel::ERROR, 'Sync Resource: Write file to local FAILED', $this->getLogData());
                }
            } else {
                $this->logger->log(LogLevel::ERROR, 'Sync Resource: Load file from URL FAILED', $this->getLogData());
            }
        }

        if ($fileObject = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->retrieveFileOrFolderObject($fullLocalPath)) {
            $uid = $fileObject->getUid();
            $this->logger->log(LogLevel::DEBUG, 'Sync Resource: Read file from local', $this->getLogData());
        } else  {
            $this->logger->log(LogLevel::ERROR, 'Sync Resource: Read file from local FAILED', $this->getLogData());
        }
        return $uid;
    }

    /**
     * @return array
     */
    private function getBaseLogData() {
        return array('importName' => $this->importName, 'tableName' => $this->tableName);
    }

    /**
     * @return array
     */
    private function getLogData() {
        $additionalLogData = $this->getBaseLogData();
        if (! empty($this->resourceUid)) {
            $additionalLogData['resourceUid'] = $this->resourceUid;
        }
        return $additionalLogData;
    }
}
