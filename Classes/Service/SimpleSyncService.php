<?php
namespace Sinso\Importlib\Service;

use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SimpleSyncService {

    /**
     * var integer
     */
    const SYNC_PREFER_SOURCE = 0;

    /**
     * var integer
     */
    const SYNC_PREFER_TARGET = 1;

    /**
     * var integer
     */
    const SYNC_FORCE = 2;

    /**
     * @var string
     */
    protected $importName;

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var array
     */
    protected $targetRow = array();

    /**
     * @var int
     */
    protected $uid = 0;

    /**
     * @var array
     */
    protected $presentUids = array();

    /**
     * @var array
     */
    protected $importHashes = array();

    /**
     * @var bool
     */
    protected $isRowUpdate = FALSE;

    /**
     * @var bool
     */
    protected $isHistoryUpdate = FALSE;

    /**
     * @var \TYPO3\CMS\Core\Log\Logger
     */
    protected $logger;

    /**
     * SysFileSyncService constructor.
     *
     * @param string $importName
     * @param string $tableName
     */
    public function __construct($importName, $tableName) {
        $this->importName = $importName;
        $this->tableName = $tableName;
        $this->logger = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\CMS\Core\Log\LogManager')->getLogger(__CLASS__);
    }

    /**
     * @param $whereFields array
     */
    public function initializeRow($whereFields) {
        $whereClause = '';

        foreach ($whereFields as $name => $value) {
            $whereClause .= " AND " . $name . " = '" . $value . "'";
        }
        $whereClause = substr($whereClause, 5);
        $this->targetRow = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('*', $this->tableName, $whereClause);

        if ($this->targetRow) {
            $this->isRowUpdate = TRUE;
            $this->uid = $this->targetRow['uid'];
            $this->logger->log(LogLevel::INFO, 'Initialize existing row', array('uid' => $this->uid));

            $historyRow = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('field_hashes', 'tx_importlib_history', $this->getHistoryWhereClause());
            if ($historyRow) {
                $this->logger->log(LogLevel::INFO, 'Initialize existing row with history', array('uid' => $this->uid));
            } else {
                $this->logger->log(LogLevel::INFO, 'Initialize existing row without history', array('uid' => $this->uid));
            }

        } else {
            $this->isRowUpdate = FALSE;
            $this->uid = 0;
            $this->targetRow = array();
            // Set the fixed field values
            foreach ($whereFields as $name => $value) {
                $this->targetRow[$name] = $value;
            }
            $historyRow = NULL;
        }

        if ($historyRow) {
            $this->isHistoryUpdate = TRUE;
            $this->importHashes = unserialize($historyRow['field_hashes']);
        } else {
            $this->isHistoryUpdate = FALSE;
            $this->importHashes = array();
        }
    }

    public function syncField($fieldName, $sourceValue, $syncStrategy = self::SYNC_PREFER_SOURCE) {
        $value = $sourceValue;
        if ($syncStrategy !== self::SYNC_FORCE) {
            $sourceHash = GeneralUtility::shortMD5($sourceValue);

            if (isset($this->importHashes[$fieldName])) {
                if ($sourceHash != $this->importHashes[$fieldName]) { // Change on source
                    if (GeneralUtility::shortMD5($this->targetRow[$fieldName]) != $this->importHashes[$fieldName]) { // Change on target -> conflict
                        if ($syncStrategy === self::SYNC_PREFER_TARGET) { // No change
                            $value = $this->targetRow[$fieldName];
                        }
                    }
                }
            }
            /* Update the import hash for the current source value. It does not necessarily match the target field value. */
            $this->importHashes[$fieldName] = $sourceHash;
        }
        $this->targetRow[$fieldName] = $value;
    }

    /**
     * Insert or updates the record based on the initialized row and the fields synced. The history table is kept up to
     * date for future imports.
     *
     * @return int  The uid updated or newly inserted.
     */
    public function insertUpdateRow() {

        if ($this->isRowUpdate) {
            $result = $GLOBALS['TYPO3_DB']->exec_UPDATEquery($this->tableName, 'uid = ' . $this->uid, $this->targetRow);
        } else {
            $result = $GLOBALS['TYPO3_DB']->exec_INSERTquery($this->tableName, $this->targetRow);
            $this->uid = $GLOBALS['TYPO3_DB']->sql_insert_id();
        }

        $history_fields = array('field_hashes' => serialize($this->importHashes));
        if ($this->isHistoryUpdate) {
            $result = $GLOBALS['TYPO3_DB']->exec_UPDATEquery('tx_importlib_history', $this->getHistoryWhereClause(), $history_fields);
        } else {
            $history_fields['import_name'] = $this->importName;
            $history_fields['table_name'] = $this->tableName;
            $history_fields['uid'] = $this->uid;
            $result = $GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_importlib_history', $history_fields);
        }

        $this->presentUids[] = $this->uid;

        return $this->uid;
    }

    /**
     * Deletes previously imported rows which are not present in the current import anymore. Import history data are
     * deleted. For the actual records only the delete flag is updated to avoid any conflicts with existing references.
     *
     * @return array Uids which got deleted.
     */
    public function deleteAbsentRows() {
        $deleteUids = array();
        /* Only rows existing in the tx_importlib_history table have to be deleted. Others are not created by this
        import and must not be deleted. */
        if (! empty($this->presentUids)) {
            $deleteRows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('uid', 'tx_importlib_history', 'uid NOT IN (' . implode(', ', $this->presentUids) . ') AND ' . $this->getHistoryBaseWhereClause());
            foreach ($deleteRows as $deleteRow) {
                $deleteUids[] = $deleteRow['uid'];
            }
        }
        if (! empty($deleteUids)) {
            $uidWhereClause = 'uid IN (' . implode(', ', $this->presentUids) . ')';
            $result = $GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_importlib_history', $uidWhereClause . ' AND ' . $this->getHistoryBaseWhereClause());
            $result = $GLOBALS['TYPO3_DB']->exec_UPDATEquery($this->tableName, $uidWhereClause, array('deleted' => 1));
        }
        return $deleteUids;
    }

    /**
     * @return string
     */
    private function getHistoryBaseWhereClause() {
        return 'import_name = \'' . $this->importName . '\' AND table_name = \'' . $this->tableName . '\'';
    }

    /**
     * @return string
     */
    private function getHistoryWhereClause() {
        return $this->getHistoryBaseWhereClause() . ' AND uid = ' . $this->uid;
    }
}
