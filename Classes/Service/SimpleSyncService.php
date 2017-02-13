<?php
namespace Sinso\Importlib\Service;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SimpleSyncService implements SingletonInterface {

    const SYNC_PREFER_SOURCE = 0;
    const SYNC_PREFER_TARGET = 1;

    /**
     * @var array
     */
    protected $importHashes = array();

    /**
     * @var array
     */
    protected $presentImportKeys = array();

    public function test() {
        return "asdf";
    }

    public function initTargetHashes($targetRow) {
        if (isset($targetRow['import_hashes'])) {
            $this->importHashes = unserialize($targetRow['import_hashes']);
        } else {
            $this->importHashes = array();
        }
    }

    public function getTargetHashes() {
        return serialize($this->importHashes);
    }

    public function addPresentImportKey($presentImportKey) {
        $this->presentImportKeys[] = $presentImportKey;
    }

    public function syncField($fieldName, $sourceValue, &$targetRow, $syncStrategy = self::SYNC_PREFER_SOURCE) {
        $this->validateFieldValue($sourceValue);
        $value = $sourceValue;
        $sourceHash = GeneralUtility::shortMD5($sourceValue);

        if (isset($this->importHashes[$fieldName])) {
            if ($sourceHash != $this->importHashes[$fieldName]) { // Change on source
                if (GeneralUtility::shortMD5($targetRow[$fieldName]) != $this->importHashes[$fieldName]) { // Change on target -> conflict
                    if ($syncStrategy === self::SYNC_PREFER_TARGET) { // No change
                        $value = $targetRow[$fieldName];
                    }
                }
            }
        }
        /* Update the import hash for the current source value. It does not necessarily match the target field value. */
        $this->importHashes[$fieldName] = $sourceHash;
        $targetRow[$fieldName] = $value;
    }

    public function validateFieldValue($value) {
        return true;
    }


    public function removeUnusedRecords() {
        // TODO: Add source primary key to DB index
        // TODO: consider language key

        $result = $GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_userjrtshop_domain_model_shopitem', 'import_key NOT IN (' . implode(', ', $this->presentImportKeys));
    }

    /**
     * @param string $url
     * @param string $localPath
     * @param string|null $localName
     * @param int $syncStrategy
     * @return bool|int
     */
    public function syncPhysicalResource($url, $localPath, $localName = null, $syncStrategy = self::SYNC_PREFER_SOURCE) {
        if (is_null($localName)) {
            $localName = \TYPO3\CMS\Core\Utility\PathUtility::basename($url);
        }
        $fullLocalPath = $localPath . DIRECTORY_SEPARATOR . $localName;

        $targetResourceExists = file_exists($fullLocalPath);
        $targetResourceHasChanged = false; //TODO: detect changes necessary for imported files?
        $sourceResourceHasChanged = true; //TODO: detect changes (ETAG, CURLOPT_NOBODY+CURLOPT_FILETIME)

        $syncResource = true;
        if ($targetResourceExists && !$sourceResourceHasChanged && !$targetResourceHasChanged) {
            $syncResource = false;
        }

        if ($syncStrategy === self::SYNC_PREFER_TARGET && $targetResourceExists && $targetResourceHasChanged) {
            $syncResource = false;
        }
        //TODO: what if file was deleted intentionally and import should not create it again?

        if ($syncResource) {
            $fileData = \TYPO3\CMS\Core\Utility\GeneralUtility::getURL($url);
            if (!$fileData) {
                return false;
            }

            if (!\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($fullLocalPath, $fileData)) {
                return false;
            }
        }

        $fileObject = \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->retrieveFileOrFolderObject($fullLocalPath);
        return $fileObject->getUid();
    }

    /**
     * @param string $table
     * @param int $uid_local
     * @param int $uid_foreign
     * @param array $whereFields
     * @param array $values
     * @param int $syncStrategy
     */
    public function syncRelation($table, $uid_local, $uid_foreign, $whereFields = array(), $values, $syncStrategy = self::SYNC_PREFER_SOURCE) {
        $isUpdate = false;
        $where = "uid_local=$uid_local and uid_foreign=$uid_foreign";

        foreach ($whereFields as $whereField) {
            $where .= " and $whereField='" . $values[$whereField] . "'";
            unset($values[$whereField]);
        }

        $targetRow = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow("*", $table, $where);
        if ($targetRow) {
            $isUpdate = true;
        }

        foreach ($values as $fieldName => $value) {
            $this->syncField($fieldName, $value, $targetRow, $syncStrategy);
        }

        $targetRow['import_hashes'] = $this->getTargetHashes();

        if ($isUpdate) {
            $result = $GLOBALS['TYPO3_DB']->exec_UPDATEquery('tx_userjrtshop_domain_model_shopitem', 'uid = ' . $targetRow['uid'], $targetRow);
        } else {
            $result = $GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_userjrtshop_domain_model_shopitem', $targetRow);
            $uid = $GLOBALS['TYPO3_DB']->sql_insert_id();
        }
    }
}
