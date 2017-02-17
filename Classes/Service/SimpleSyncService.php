<?php
namespace Sinso\Importlib\Service;

use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SimpleSyncService
 *
 * @package Sinso\Importlib\Service
 */
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
     * Initialize a new row to import. Checks whether row is an insert or an update, and the same for the history record
     * that stores the related import hashes.
     *
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

            $historyRow = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('field_hashes', 'tx_importlib_history', $this->getHistoryWhereClause());
            if ($historyRow) {
                $this->logger->log(LogLevel::INFO, 'Initialize: Existing target row with history initialized', $this->getLogData());
            } else {
                $this->logger->log(LogLevel::INFO, 'Initialize: Existing target row without history initialized', $this->getLogData());
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

            $this->logger->log(LogLevel::INFO, 'Initialize: New row initialized', $this->getLogData());
        }

        if ($historyRow) {
            $this->isHistoryUpdate = TRUE;
            $this->importHashes = unserialize($historyRow['field_hashes']);
        } else {
            $this->isHistoryUpdate = FALSE;
            $this->importHashes = array();
        }
    }

    /**
     * Sync a specific field. Changes since the last import are handled by the given sync strategy.
     *
     * @param string $fieldName
     * @param mixed $sourceValue
     * @param int $syncStrategy
     */
    public function syncField($fieldName, $sourceValue, $syncStrategy = self::SYNC_PREFER_SOURCE) {
        $value = $sourceValue;
        if ($syncStrategy !== self::SYNC_FORCE) {
            $sourceHash = GeneralUtility::shortMD5($sourceValue);

            if (isset($this->importHashes[$fieldName])) {
                if ($sourceHash != $this->importHashes[$fieldName]) { // Change on source
                    if (GeneralUtility::shortMD5($this->targetRow[$fieldName]) != $this->importHashes[$fieldName]) { // Change on target -> conflict
                        if ($syncStrategy === self::SYNC_PREFER_TARGET) { // No change
                            $value = $this->targetRow[$fieldName];
                            $this->logger->log(LogLevel::DEBUG, 'Sync Field: Field ' . $fieldName . ' not updated (SYNC PREFER TARGET)', $this->getLogData());
                        } else {
                            $this->logger->log(LogLevel::DEBUG, 'Sync Field: Field ' . $fieldName . ' set to ' . $sourceValue . ' (SYNC PREFER SOURCE)', $this->getLogData());
                        }
                    } else {
                        $this->logger->log(LogLevel::DEBUG, 'Sync Field: Field ' . $fieldName . ' set to ' . $sourceValue . ' (NO CHANGE ON TARGET)', $this->getLogData());
                    }
                } else {
                    $value = $this->targetRow[$fieldName];
                    $this->logger->log(LogLevel::DEBUG, 'Sync Field: Field ' . $fieldName . ' not updated (NO CHANGE ON SOURCE)', $this->getLogData());
                }
            } else {
                $this->logger->log(LogLevel::DEBUG, 'Sync Field: Field ' . $fieldName . ' set to ' . $sourceValue . ' (NEW)', $this->getLogData());
            }
            /* Update the import hash for the current source value. It does not necessarily match the target field value. */
            $this->importHashes[$fieldName] = $sourceHash;
        } else {
            $this->logger->log(LogLevel::DEBUG, 'Sync Field: Field ' . $fieldName . ' set to ' . $sourceValue . ' (FORCED)', $this->getLogData());
        }
        $this->targetRow[$fieldName] = $value;
    }

    /**
     * Inserts or updates the record based on the initialized row and the fields synced. The history table is kept up to
     * date for future imports.
     *
     * @return int  The uid updated or newly inserted.
     */
    public function insertUpdateRow() {
        if ($this->isRowUpdate) {
            if ($result = $GLOBALS['TYPO3_DB']->exec_UPDATEquery($this->tableName, 'uid = ' . $this->uid, $this->targetRow)) {
                $this->logger->log(LogLevel::INFO, 'Insert/Update: Target row updated', $this->getLogData());
            } else {
                $this->logger->log(LogLevel::ERROR, 'Insert/Update: Target row update FAILED', $this->getLogData());
            }
        } else {
            if ($result = $GLOBALS['TYPO3_DB']->exec_INSERTquery($this->tableName, $this->targetRow)) {
                $this->uid = $GLOBALS['TYPO3_DB']->sql_insert_id();
                $this->logger->log(LogLevel::INFO, 'Insert/Update: Target row inserted', $this->getLogData());
            } else {
                $this->logger->log(LogLevel::ERROR, 'Insert/Update: Target row insert FAILED', $this->getLogData());
            }
        }
        if ($result) {
            $history_fields = array('field_hashes' => serialize($this->importHashes));
            if ($this->isHistoryUpdate) {
                if ($GLOBALS['TYPO3_DB']->exec_UPDATEquery('tx_importlib_history', $this->getHistoryWhereClause(), $history_fields)) {
                    $this->logger->log(LogLevel::INFO, 'Insert/Update: History row updated', $this->getLogData());
                } else {
                    $this->logger->log(LogLevel::ERROR, 'Insert/Update: History row update FAILED', $this->getLogData());
                }
            } else {
                $history_fields['import_name'] = $this->importName;
                $history_fields['table_name'] = $this->tableName;
                $history_fields['uid'] = $this->uid;
                if($GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_importlib_history', $history_fields)) {
                    $this->logger->log(LogLevel::INFO, 'Insert/Update: History row inserted', $this->getLogData());
                } else {
                    $this->logger->log(LogLevel::ERROR, 'Insert/Update: History row insert FAILED', $this->getLogData());
                }
            }
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
            $logData = $this->getBaseLogData();
            foreach ($deleteRows as $deleteRow) {
                $deleteUids[] = $deleteRow['uid'];
                $logData['uid'] = $deleteRow['uid'];
                $this->logger->log(LogLevel::INFO, 'Delete: Absent target row found', $logData);
            }
        }
        if (empty($deleteUids)) {
            $this->logger->log(LogLevel::INFO, 'Delete: No absent target rows for delete found', $this->getBaseLogData());
        } else {
            $uidWhereClause = 'uid IN (' . implode(', ', $deleteUids) . ')';
            if ($GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_importlib_history', $uidWhereClause . ' AND ' . $this->getHistoryBaseWhereClause())) {
                $this->logger->log(LogLevel::INFO, 'Delete: Absent histroy rows deleted', $this->getBaseLogData());
            } else {
                $this->logger->log(LogLevel::ERROR, 'Delete: Absent history rows delete FAILED', $this->getBaseLogData());
            }
            if ($GLOBALS['TYPO3_DB']->exec_UPDATEquery($this->tableName, $uidWhereClause, array('deleted' => 1))) {
                $this->logger->log(LogLevel::INFO, 'Delete: Deleted flag on absent target rows set', $this->getBaseLogData());
            } else {
                $this->logger->log(LogLevel::ERROR, 'Delete: Set deleted flag on absent target rows FAILED', $this->getBaseLogData());
            }
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
        if (!empty($this->uid)) {
            $additionalLogData['uid'] = $this->uid;
        }
        return $additionalLogData;
    }
}
