<?php
namespace FormatD\Importer\Command;

/*                                                                        *
 * This script belongs to the Neos Flow package "FormatD.Importer".       *
 *                                                                        *
 *                                                                        */

use Neos\Flow\Annotations as Flow;

/**
 * Import command for the FormatD.Importer package
 *
 * @Flow\Scope("singleton")
 */
class ImportCommandController extends \Neos\Flow\Cli\CommandController {

	/**
	 * @Flow\Inject
	 * @var \FormatD\Importer\Domain\Service\XmlImportService
	 */
	protected $xmlImportService;
	
	/**
	 * Import data from xml file or directory with files
	 * Usage example: ./flow import:xml resource://My.Package/Private/InitResources/InitData.xml
	 *
	 * @param string $pathAndFilename Path to an xml-file or directory containing the data to import
	 * @param string $initializePathAndFilename Path to an xml-file or directory containing data with referenced ids
	 * @param boolean $enableXmlModification Activate this to inject an update identifier for later update
	 * @return void
	 */
	public function xmlCommand($pathAndFilename, $initializePathAndFilename = '', $enableXmlModification = FALSE) {
		ini_set('xdebug.max_nesting_level', 1000);

		if ($initializePathAndFilename !== '') {
			if(is_dir($initializePathAndFilename)) {
				$this->xmlImportService->initializeFromDirectory($initializePathAndFilename);
			} else {
				$this->xmlImportService->initializeFromFile($initializePathAndFilename);
			}
		}

		if ($enableXmlModification) {
			$this->xmlImportService->setXmlModificationForUpdatesEnabled(TRUE);
		}

		if(is_dir($pathAndFilename)) {
			$this->xmlImportService->importFromDirectory($pathAndFilename);
		} else {
			$this->xmlImportService->importFromFile($pathAndFilename);
		}
	}
	
}

?>