<?php

class SilverStripeContentImporter extends ExternalContentImporter {

	/**
	 * Override this to specify additional import handlers
	 *
	 * @var array
	 */
	private static $importer_classes = array();

	private static $last_sitetree_id = -1;

	public function __construct() {
		$this->init();
	}

	public function init() {
		$this->contentTransforms['DataObject'] = new SilverStripeDataObjectImporter();
		$this->contentTransforms['UserDefinedForm'] = new SilverStripeFormImporter();
		$this->contentTransforms['EditableDropdown'] = new SilverStripeEditableDropdownImporter();
		$this->contentTransforms['EditableOption'] = new SilverStripeEditableOptionImporter();

		foreach ($this->config()->importer_classes as $type => $cls) {
			$this->contentTransforms[$type] = new $cls;
		}
	}

	/**
	 * Description
	 * @param type $contentItem 
	 * @param type $target 
	 * @param bool $includeParent 
	 * @param bool $includeChildren 
	 * @param string $duplicateStrategy 
	 * @param array $params 
	 */
	public function import($contentItem, $target, $includeParent = false, $includeChildren = true, $duplicateStrategy='Overwrite', $params = array())
	{
		set_time_limit(600);
		if(isset($params['MultisiteImport']) && $params['MultisiteImport'] == 1) {
			if(!SiteTree::has_extension('ImportedDataExtension') || !File::has_extension('ImportedDataExtension')) {
				user_error("Multisite import will not work without ImportedDataExtension extending SiteTree and File dataobjects", E_USER_ERROR);
				return;
			}

			// Having these check config first allows for retroactive fixing if required
			if(Config::inst()->get('SilverStripeContentImporter', 'last_sitetree_id') == -1) {
				$lastSitetree = SiteTree::get()->sort('ID DESC')->first();
				self::$last_sitetree_id = $lastSitetree->ID;
			} else {
				self::$last_sitetree_id = Config::inst()->get('SilverStripeContentImporter', 'last_sitetree_id');
			}
		}
		parent::import($contentItem, $target, $includeParent, $includeChildren, $duplicateStrategy, $params);
	}

	protected function getExternalType($item) {

		if ($item->ClassName) {
			$name = null;
			$hierarchy = ClassInfo::ancestry($item->ClassName);
			foreach ($hierarchy as $ancestor => $val) {
				if (isset($this->contentTransforms[$ancestor])) {
					$name = $ancestor;
				}
			}
			if ($name) {
				return $name;
			}
		}
		return 'DataObject';
	}

	/**
	 * Method to go through after the import ends and if it's a Multisite import
	 * will attempt to rebind all of the 
	 */
	public function runOnImportEnd()
	{
		// Don't execute if we're not doing a multisite import
		if(self::$last_sitetree_id == -1) {
			return;
		}

		// Set to Stage to read all pages
		Versioned::reading_stage('Stage');
		// Grab all pages after the stored last sitetree ID
		$sitetreeObjects = SiteTree::get()->filter(array('ID:GreaterThan' => self::$last_sitetree_id));
		// Loop over the sitetree objects
		foreach($sitetreeObjects as $sitetreeObject) {
			// Store if the page is published
			$isPublished = $sitetreeObject->isPublished();

			// Loop over hasones
			$hasOnes = $sitetreeObject->has_one();
			foreach($hasOnes as $hasOneKey => $hasOneVal) {
				if($hasOneVal == "Site" || $hasOneKey == "Parent") {
					continue;
				}
				$this->fixRecordLink($sitetreeObject, $hasOneKey, $hasOneVal, true);
			}

			// Loop over hasmanys
			$hasManys = $sitetreeObject->has_many();
			foreach($hasManys as $hasManyKey => $hasManyVal) {
				if($hasManyVal == "Site" || $hasManyKey == "Parent") {
					continue;
				}
				$this->fixRecordLink($sitetreeObject, $hasManyKey, $hasManyVal);
			}

			// Loop over manymanys
			$manyManys = $sitetreeObject->many_many();
			foreach($manyManys as $manyManyKey => $manyManyVal) {
				if($manyManyVal == "Site" || $manyManyKey == "Parent") {
					continue;
				}
				$this->fixRecordLink($sitetreeObject, $manyManyKey, $manyManyVal);
			}

			// Write sitetree objects
			$sitetreeObject->write();

			// Publish if published
			if($isPublished) {
				$sitetreeObject->publish('Stage', 'Live');
			}
		}
	}

	/**
	 * Method which searches for relations based on the RemoteNodeId to update the relations when
	 * the connector is used on a multisite based instance
	 * @param DataObject &$object 
	 * @param string $relationKey 
	 * @param string $relationVal 
	 * @param bool $hasOne 
	 */
	protected function fixRecordLink(&$object, $relationKey, $relationVal = '', $hasOne = false)
	{
		$idStr = $relationKey.'ID';

		// Has_One
		if($hasOne && $relatedObjectID = (int) $object->$idStr) {
			
			// New ID is the old ID until we find a match.
			$newID = $relatedObjectID;

			$anc = ClassInfo::ancestry($relationVal);
			
			// Check "valid" types and grab the object by the ID of what they were on the remote server
			// Update the ID
			if(in_array('File', $anc)) {
				if($new = File::get()->filter(array('RemoteNodeId' => $relatedObjectID))->first()) {
					$newID = $new->ID;
				}
			} elseif(in_array('SiteTree', $anc)) {
				if($new = SiteTree::get()->filter(array('RemoteNodeId' => $relatedObjectID))->first()) {
					$newID = $new->ID;
				}
			}

			// Replace IDs with new ID.
			$object->$idStr = $newID;
			return;
		}

		// Has_Many, etc
		if(!method_exists($object, $relationKey)) {
			return;
		}

		$relatedObjects = $object->$relationKey();
		if(!$hasOne && $relatedObjects->count() > 0) {
			$replaceMap = array();
			foreach($relatedObjects as $relatedObject) {
				$relatedObjectID = $relatedObject->ID;
				
				$anc = ClassInfo::ancestry($relatedObject);
				$replacement = null;

				// For the most part - the has manys seemed pretty in tact.
				// This is just more of a check to make sure things are correct
				if(in_array('File', $anc)) {
					if($remoteNodeID = $relatedObject->RemoteNodeId) {
						$replacement = $relationVal::get()->byID($newID);
					}
				} elseif(in_array('SiteTree', $anc)) {
					if($remoteNodeID = $relatedObject->RemoteNodeId) {
						$replacement = $relationVal::get()->byID($newID);
					}
				}

				if($replacement) {
					$replaceMap[] = array($relatedObjectID, $replacement);
				}
			}

			// Not merged in above, so we aren't writing and reading at the same time
			foreach($replaceMap as $replaceData) {
				$object->$relationKey()->removeByID($replaceData[0]);
				$object->$relationKey()->add($replaceData[1]);
			}
			return;
		}
	}
}
