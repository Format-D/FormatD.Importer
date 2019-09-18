<?php
namespace FormatD\Importer\Domain\Service;

/*                                                                        *
 * This script belongs to the Neos Flow package "FormatD.Importer".       *
 *                                                                        *
 *                                                                        */

use Neos\Flow\Annotations as Flow;

/**
 * Service for importing XML files
 *
 * @Flow\Scope("singleton")
 */
class XmlImportService {
		
	/**
	 * @Flow\Inject
	 * @var \Neos\Flow\ObjectManagement\ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @Flow\Inject
	 * @var \Neos\Flow\Persistence\PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * @var bool
	 */
	protected $persistenceEnabled = TRUE;

	/**
	 * @var bool
	 */
	protected $xmlModificationForUpdatesEnabled = FALSE;

	/**
	 * dynamically created reposiotries
	 * @var array
	 */
	protected $repositories = array();
		
	/**
	 * Objects with id attribute are stored in this cache for later referal by reference attribute
	 * @var array 
	 */
	protected $referenceStorage = array();

	/**
	 * Map of references to persistence identifiers (built by initialize call)
	 * @var array
	 */
	protected $referencePersistenceMap = array();

	/**
	 * Autoincrement values are stored in this cache to auto create sortings
	 * @var array
	 */
	protected $autoincrementStorage = array();

	/**
	 * Used by the randomize feature (activated by xml attributes)
	 * @var array
	 */
	protected $randomIncrements = array();

	/**
	 * Directory of currently imported file
	 * @var string
	 */
	protected $currentImportDir;

	/**
	 * Filename of currently imported file
	 * @var string
	 */
	protected $currentImportFile;

	/**
	 * currently imported xml
	 * @var \SimpleXMLElement
	 */
	protected $currentXml;

	/**
	 * @param boolean $persistenceEnabled
	 */
	public function setPersistenceEnabled($persistenceEnabled) {
		$this->persistenceEnabled = $persistenceEnabled;
	}

	/**
	 * @param boolean $xmlModificationForUpdatesEnabled
	 */
	public function setXmlModificationForUpdatesEnabled($xmlModificationForUpdatesEnabled) {
		$this->xmlModificationForUpdatesEnabled = $xmlModificationForUpdatesEnabled;
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function initializeFromDirectory($path) {
		$files = \Neos\Utility\Files::readDirectoryRecursively($path);
		
		usort($files, function($a, $b) {
			$a_position = intval(preg_replace('#^.*/([0-9]{3})_.*$#', '$1', $a));
			$b_position = intval(preg_replace('#^.*/([0-9]{3})_.*$#', '$1', $b));

			if($a_position === $b_position) return 0;
			return ($a_position < $b_position) ? -1 : 1;
		});

		foreach ($files as $file) {
			$this->initializeFromFile($file);
		}
	}

	/**
	 * Initializes from single XML File (loads references)
	 *
	 * @param string $pathAndFilename
	 * @api
	 */
	public function initializeFromFile($pathAndFilename) {

		if(!preg_match('#^.*\.xml$#', $pathAndFilename)) return;

		if (!is_file($pathAndFilename)) {
			throw new \Exception('File not Found: '.$pathAndFilename, 1373386104);
		}

		$xmlString = \Neos\Utility\Files::getFileContents($pathAndFilename);
		$xml = new \SimpleXMLElement($xmlString);

		if (!isset($xml->meta) || !isset($xml->content)) return;

		$references = $xml->xpath('/descendant::*[@id]');

		foreach ($references as $referenceNode) {

			if (strlen((string) $referenceNode['type']) < 2) {
				throw new \Exception('Type not defined in id node :' . $referenceNode->asXML());
			}

			if (strlen((string) $referenceNode['persistence-id']) < 2) {
				throw new \Exception('persistence-id not defined in id node :' . $referenceNode->asXML());
			}

			$this->referencePersistenceMap[(string) $referenceNode['type']][(string) $referenceNode['id']] = (string) $referenceNode['persistence-id'];
		}
	}

	/**
	 * Imports Folder with XML Files
	 *
	 * @param string $path
	 * @api
	 */
	public function importFromDirectory($path) {

		$files = \Neos\Utility\Files::readDirectoryRecursively($path);

		usort($files, function($a, $b) {
			$a_position = intval(preg_replace('#^.*/([0-9]{3})_.*$#', '$1', $a));
			$b_position = intval(preg_replace('#^.*/([0-9]{3})_.*$#', '$1', $b));

			if($a_position === $b_position) return 0;
			return ($a_position < $b_position) ? -1 : 1;
		});

		$errorFlag = FALSE;

		foreach ($files as $file) {
			try {
				$this->importFromFile($file);
			} catch (\Exception $e) {
				throw $e;
				echo $e->getMessage()."\n\n";
				$errorFlag = TRUE;
			}
		}

		if ($errorFlag) {
			throw new \Exception('Errors occurred during import. Changes in dadabase not persisted!');
		}
	}

	/**
	 * Imports single XML File
	 * 
	 * @param string $pathAndFilename
	 * @api
	 */
	public function importFromFile($pathAndFilename) {

		if(!preg_match('#^.*\.xml$#', $pathAndFilename)) return;

		if (!is_file($pathAndFilename)) {
			throw new \Exception('File not Found: '.$pathAndFilename, 1373386104);
		}

		$this->currentImportDir = dirname($pathAndFilename);
		$this->currentImportFile = basename($pathAndFilename);

		//echo 'importing resource: ' . $this->currentImportDir . '/' . $this->currentImportFile . "\n";

		$xmlString = \Neos\Utility\Files::getFileContents($pathAndFilename);
		$xml = new \SimpleXMLElement($xmlString);
		$this->currentXml = $xml;

		if (!isset($xml->meta) || !isset($xml->content)) return;

		if ($this->persistenceEnabled) {
			foreach ($xml->meta->children() as $mataName => $metaNode) {
				switch ($mataName) {
					case 'repository':
						$this->repositories[(string) $metaNode['type']] = $this->objectManager->get((string) $metaNode['repositoryName']);
						break;
				}
			}
		}
		
		foreach ($xml->content->children() as $node) {
			$this->importRootNode($node);
		}

		if ($this->xmlModificationForUpdatesEnabled) {

			$xmlString = $xml->asXML();

			/*
			$dom = new \DOMDocument('1.0');
			$dom->preserveWhiteSpace = FALSE;
			$dom->formatOutput = TRUE;
			$dom->loadXML($xml->asXML());
			$xmlString = $dom->saveXML();
			$xmlString = str_replace("  ", "\t", $xmlString);
			*/

			file_put_contents($pathAndFilename, $xmlString);
		}

	}

	/**
	 * Returns already imported object by reference (used e.g. by testcases)
	 *
	 * @param string $type
	 * @param string $reference
	 * @return object
	 */
	public function getImportedObjectByReference($type, $reference) {
		if (!isset($this->referenceStorage[$type]) || !isset($this->referenceStorage[$type][$reference])) {
			throw new \Exception('reference ' . $reference . ' of type ' . $type . ' not found');
		}

		return $this->referenceStorage[$type][$reference];
	}

	/**
	 * Import first level node (inside content)
	 *
	 * @param \SimpleXMLElement $node
	 */
	protected function importRootNode(\SimpleXMLElement $node) {
		foreach ($node as $childnode) {
			if (isset($childnode['randomDuplicates'])) {
				for ($i = 0; $i < intval($childnode['randomDuplicates']); $i++) {
					$this->importNode($childnode);
				}
			} else {
				$this->importNode($childnode);
			}
		}
	}

	/**
	 * Import a node recursively
	 *
	 * @param \SimpleXMLElement $node
	 */
	protected function importNode(\SimpleXMLElement $node) {
		$updateMode = isset($node->updateIdentifier);
		$object = $this->convertNode($node);
		if ($this->persistenceEnabled) {
			if (!isset($this->repositories[\Neos\Utility\TypeHandling::getTypeForValue($object)])) {
				$this->raiseException('No Repository specified in meta section for class '.\Neos\Utility\TypeHandling::getTypeForValue($object), 1368431488);
			}

			if ($updateMode) {
				$this->repositories[\Neos\Utility\TypeHandling::getTypeForValue($object)]->update($object);
			} else {
				$this->repositories[\Neos\Utility\TypeHandling::getTypeForValue($object)]->add($object);
			}
		}
	}

	/**
	 * Convert node to corresponding type
	 *
	 * @param \SimpleXMLElement $node
	 * @return array|bool|mixed|string
	 */
	protected function convertNode(\SimpleXMLElement $node) {

		if (isset($node['reference'])) {
			$className = (string) $node['type'];
			if (isset($this->referencePersistenceMap[$className]) && isset($this->referencePersistenceMap[$className][(string) $node['reference']])) {
				$node['persistence-reference'] = $this->referencePersistenceMap[$className][(string) $node['reference']];
			}
		}

		if (isset($node['persistence-reference'])) {
			$className = (string) $node['type'];
			$object = $this->persistenceManager->getObjectByIdentifier((string) $node['persistence-reference'], $className);
			if ($object === NULL) {
				throw new \Exception('Object linked via persistence-reference not found: ' . $node['persistence-reference'] . ', ' . $className);
			}
			return $object;
		}

		if (isset($node['reference'])) {
			$className = (string) $node['type'];
			if (!isset($this->referenceStorage[$className])) {
				$this->referenceStorage[$className] = array();
			}

			if (!isset($this->referenceStorage[$className][(string) $node['reference']])) {

					// in case the referenced object is created at a later stage of this import
					// we search for it and create it in advance
				$referencedXmlNodes = $this->currentXml->xpath('/descendant::*[attribute::id="' . ((string) $node['reference']) . '"][attribute::type="' . $className . '"]');

				if(count($referencedXmlNodes) > 0) {
					$referencedXmlNode = $referencedXmlNodes[0];
					$ret = $this->convertToObject($referencedXmlNode);

					if ($this->xmlModificationForUpdatesEnabled) {
						$referencedXmlNode['persistence-reference'] = $this->persistenceManager->getIdentifierByObject($ret);
						$node['persistence-reference'] = $this->persistenceManager->getIdentifierByObject($ret);
					} else {
						$referencedXmlNode['id'] = '';
						$referencedXmlNode['reference'] = (string) $node['reference'];
					}
					return $ret;
				} else {
					$this->raiseException('Reference "'.$node['reference'].'" of type "'.$node['type'].'" not found in node: '.$node->asXML(), 1368431516);
				}
			}

			if ($this->xmlModificationForUpdatesEnabled) {
				$node['persistence-reference'] = $this->persistenceManager->getIdentifierByObject($this->referenceStorage[$className][(string) $node['reference']]);
			}
			return $this->referenceStorage[$className][(string) $node['reference']];
		}
		
		if (isset($node['type'])) {
			switch($node['type']) {
				case "string":
					return $this->convertToString($node);
					break;

				case "integer":
					return $this->convertToInteger($node);
					break;

				case "double":
					return $this->convertToDouble($node);
					break;

				case "boolean":
					return $this->convertToBoolean($node);
					break;
					
				case "array":
					return $this->convertToArray($node);
					break;

				case "path":
					return $this->convertToPath($node);
					break;
				
				default:
					return $this->convertToObject($node);
					break;
			}
		}

		return $this->convertToString($node);
	}

	/**
	 * Convert node to string
	 *
	 * @param $node
	 * @return string
	 */
	protected function convertToString(\SimpleXMLElement $node) {
		$value = (string) $node;
		if (isset($node['random'])) {
			if ($node['random'] == 'prependHash') {
				$value = uniqid($value . ' ');
			} else if ($node['random'] == 'UUID') {
				$value = sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
			}
		}
		return $value;
	}

	/**
	 * Convert node to integer
	 *
	 * @param $node
	 * @return integer
	 */
	protected function convertToInteger(\SimpleXMLElement $node) {
		$value = intval((string) $node);
		if (isset($node['random'])) {
			if ($node['random'] == 'increment') {
				if (!isset($this->randomIncrements[$node->getName()])) {
					$this->randomIncrements[$node->getName()] = 0;
				}
				$value = $this->randomIncrements[$node->getName()]++;
			} else if (strpos($node['random'], '|') !== FALSE) {
				$parts = explode('|', $node['random']);
				$value = rand(intval($parts[0]),intval($parts[1]));
			}
		}
		return $value;
	}

	/**
	 * Convert node to double
	 *
	 * @param $node
	 * @return double
	 */
	protected function convertToDouble(\SimpleXMLElement $node) {
		$value = doubleval((string) $node);
		if (isset($node['random'])) {
			if (strpos($node['random'], '|') !== FALSE) {
				$parts = explode('|', $node['random']);
				$value = doubleval(rand(intval($parts[0]) * 100, intval($parts[1]) * 100) / 100);
			}
		}
		return $value;
	}

	/**
	 * Convert node to boolean
	 *
	 * @param $node
	 * @return bool
	 */
	protected function convertToBoolean(\SimpleXMLElement $node) {
		if ((string) $node == 'TRUE') return TRUE;
		return FALSE;
	}

	/**
	 * Convert node to array
	 *
	 * @param $node
	 * @return array
	 */
	protected function convertToArray(\SimpleXMLElement $node) {
		$array = array();
		foreach ($node->children() as $elemname => $elem) {
			if (preg_match('#^elem[0-9]+$#', $elemname)) {
				$array[(int) str_replace('elem', '', $elemname)] = $this->convertNode($elem);
			} else {
				$array[$elemname] = $this->convertNode($elem);
			}
		}
		return $array;
	}

	/**
	 * @param $node
	 */
	protected function convertToPath(\SimpleXMLElement $node) {
		$path = (string) $node;

		if (!preg_match('#^resource://.*$#', $path)) {
			$path = $this->currentImportDir.'/'.$path;
		}

		if (!file_exists($path)) {
			$this->raiseException('File not found: ' . $path, 1368431557);
		}

		return $path;
	}

	/**
	 * Convert node to object
	 *
	 * @param $node
	 * @return mixed
	 */
	protected function convertToObject($node) {

		$className = (string) $node['type'];
		$updateMode = FALSE;

		if (isset($node->updateIdentifier)) {
			if ((string) $node->updateIdentifier['name'] == 'reference') {
				if (!isset($this->referenceStorage[$className])
					|| !isset($this->referenceStorage[$className][(string) $node->updateIdentifier])) {
					$this->raiseException('Reference "'.(string) $node->updateIdentifier.'" of type "'.$className.'" not found in node: '.$node->asXML(), 1372882229);
				}
				$object = $this->referenceStorage[$className][(string) $node->updateIdentifier];
			} else {
				$updateMode = TRUE;
				if (!$this->persistenceEnabled) {
					throw new \Exception('Persistence must be enabled when using update identifier in the xml!', 1373535804);
				}

				if ((string) $node->updateIdentifier['name'] == 'flowPersistenceIdentifier') {
					$object = $this->persistenceManager->getObjectByIdentifier((string) $node->updateIdentifier, $className);
				} else {
					$findMethodName = 'findOneBy'.ucfirst($node->updateIdentifier['name']);
					$object = $this->repositories[$className]->$findMethodName((string) $node->updateIdentifier);
				}
			}
		} else {
			$factory = $this->objectManager;
			$factoryMethodName = 'get';
			$arguments = array();

			if (isset($node['factoryClassName'])) {
				$factory = $this->objectManager->get((string) $node['factoryClassName']);
				if(isset($node['factoryMethodName'])) {
					$factoryMethodName = (string) $node['factoryMethodName'];
				}
			} else {
				$arguments[] = $className;
			}

			if (isset($node->constructorArguments)) {
				foreach ($node->constructorArguments->children() as $argument) {
					$arguments[] = $this->convertNode($argument);
				}
			}

				// if objectmanager has not been injected (like for unit tests) use "new" keyword
			if ($factory === NULL) {
				$object = new $className();
			} else {
				$object = call_user_func_array(array($factory, $factoryMethodName), $arguments);
			}
		}
		
		if (isset($node->properties)) {
			foreach ($node->properties->children() as $propertyName => $property) {
				$setterMethodName = 'set'.ucfirst($propertyName);
				if (!method_exists($object, $setterMethodName)) $this->raiseException('No Setter "'.$setterMethodName.'" defined. Node: '.$node->asXML(), 1366233034);
				$object->$setterMethodName($this->convertNode($property));
			}
		}
		
		if (isset($node->relations)) {
			foreach ($node->relations->children() as $relationName => $relation) {
				$addMethodName = 'add'.ucfirst($relationName);

				if (isset($relation['randomDuplicates'])) {
					for ($i = 0; $i < intval($relation['randomDuplicates']); $i++) {
						$object->$addMethodName($this->convertNode($relation));
					}
				} else {
					$object->$addMethodName($this->convertNode($relation));
				}
			}
		}
		
		if (isset($node['id'])) {
			if ($this->xmlModificationForUpdatesEnabled) {
				$node['persistence-id'] = $this->persistenceManager->getIdentifierByObject($object);
			}
			$this->referenceStorage[(string) $node['type']][(string) $node['id']] = $object;
		}

		if ($this->xmlModificationForUpdatesEnabled) {
			$this->setNodeUpdateIdentifier($node, $object);
		}
		
		if (isset($node['repositoryAction']) && $this->persistenceEnabled) {

			if ((string) $node['repositoryAction'] == "add" && $updateMode) {
				$node['repositoryAction'] = 'update';
			}

			if ((string) $node['repositoryAction'] == "add") {
				if (!isset($this->repositories[$className])) {
					$this->raiseException('No Repository specified in meta section for class '.$className, 1377171196);
				}
				$this->repositories[$className]->add($object);
			}
			if ((string) $node['repositoryAction'] == "update") {
				if (!isset($this->repositories[$className])) {
					$this->raiseException('No Repository specified in meta section for class '.$className, 1377171198);
				}
				$this->repositories[$className]->update($object);
			}
		}

		if (isset($node['autoIncrementField']) && !$updateMode) {
			$setterMethodName = 'set'.ucfirst((string) $node['autoIncrementField']);
			if (!isset($this->autoincrementStorage[$className])) {
				$this->autoincrementStorage[$className] = 0;
			}
			$object->$setterMethodName($this->autoincrementStorage[$className]++);
		}
		
		return $object;
	}

	/**
	 * @param \SimpleXMLElement $node
	 * @param object $object
	 */
	protected function setNodeUpdateIdentifier(\SimpleXMLElement $node, $object) {

		$identifier = $this->persistenceManager->getIdentifierByObject($object);
		if ($identifier === NULL) return;

		if (strlen((string) $node->updateIdentifier) > 0) {
			$node->updateIdentifier = $this->persistenceManager->getIdentifierByObject($object);
			$node->updateIdentifier['name'] = 'flowPersistenceIdentifier';
		} else {
			$domNode = dom_import_simplexml($node);

			$newElement = $domNode->ownerDocument->createElement('updateIdentifier', $this->persistenceManager->getIdentifierByObject($object));
			$newElement->setAttribute('name', 'flowPersistenceIdentifier');

			$new = $domNode->insertBefore(
				$newElement,
				$domNode->firstChild
			);

			simplexml_import_dom($new, get_class($node));
		}
	}

	/**
	 * Raises Exception in import process
	 *
	 * @param string $message
	 * @param integer $code
	 * @throws \Exception
	 */
	protected function raiseException($message, $code) {
		throw new \Exception('Error during import of file "'.$this->currentImportDir.'/'.$this->currentImportFile.'": ' . $message, $code);
	}
}