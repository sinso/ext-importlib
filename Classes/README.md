# Import Library for TYPO3

Library for record imports into the TYPO3 CMS. It provides simple functions only. The main import logic still has to be 
implemented as it may vary from case to case. It is not a magic importer for everything ;-)

## Features
- Insert, update, delete
- Multilanguage
- Resource handling
- Sync strategies to resolve update conflicts
- Logging

## Usage

Sample for basic usage:

    /**
     * @var \Sinso\Importlib\Service\SimpleSyncService
     */
    protected $simpleSyncService;

    /**
     * @var \Sinso\Importlib\Service\SysFileSyncService
     */
    protected $sysFileSyncService;
    
    public function import() {
        $data = \TYPO3\CMS\Core\Utility\GeneralUtility::getUrl('import.json';
        $jsonData = json_decode($data, TRUE);
        
        foreach($jsonData as $sourceItem) {
        
            $this->simpleSyncService->initializeRow(array('uid' => $sourceItem['uid']));
            $this->simpleSyncService->syncField('name', $sourceItem['name'], SimpleSyncService::SYNC_PREFER_SOURCE);
            $this->simpleSyncService->syncField('deleted', 0, SimpleSyncService::SYNC_FORCE);
            
            if ($recordUid = $this->simpleSyncService->insertUpdateRow()) {

                foreach ($sourceItem['image']['url'] as $imageUrl) {
                    $this->sysFileSyncService->initializeResource($recordUid, $imageUrl);
                    $this->sysFileSyncService->insertUpdateRow();
                }
            }
            
        }
        $uidsToDelete = $this->simpleSyncService->getAbsentRowsToDelete();
        $this->simpleSyncService->deleteAbsentRows($uidsToDelete);
        $this->sysFileSyncService->deleteAbsentRows();
     }

Multilanguage is handled by the field properties. The logic mainly depends on the actual import algorithm chosen by the
import file structure. However, the following identifiers have to be set accordingly:

    $this->simpleSyncService->syncField('sys_language_uid', $languageId, SimpleSyncService::SYNC_FORCE);
    $this->simpleSyncService->syncField('l10n_parent', $parentUid, SimpleSyncService::SYNC_FORCE);