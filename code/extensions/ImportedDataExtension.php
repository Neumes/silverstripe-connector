<?php

/**
 * Records data of the imported source of content. Projects can add this to pages and
 * files to record when a node came from
 *
 * @author marcus
 */
class ImportedDataExtension extends DataExtension {
	private static $db = array(
		'RemoteSystemId'		=> 'Varchar(255)',
		'RemoteNodeId'			=> 'Varchar(255)',
		'SourceClassName'		=> 'Varchar(255)'
	);
}
