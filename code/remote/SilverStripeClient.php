<?php
/**

Copyright (c) 2009, SilverStripe Australia PTY LTD - www.silverstripe.com.au
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

* Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
* Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the
documentation and/or other materials provided with the distribution.
* Neither the name of SilverStripe nor the names of its contributors may be used to endorse or promote products derived from this software
without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.

*/

class SilverStripeClient
{
	public function __construct()
	{
		$this->api = new WebApiClient(null, self::$methods);
		$this->api->setUseCookies(true);
		$this->api->setMaintainSession(true);

		$this->api->addReturnHandler('dataobjectset', new DataObjectSetReturnHandler());
		$this->api->addReturnHandler('dataobject', new DataObjectReturnHandler());
	}

	public function connect($url, $username, $password)
	{
		$this->api->setBaseUrl($url);
		$this->api->setAuthInfo($username, $password);
	}

	public function isConnected()
	{
		return $this->api->getBaseUrl() != null;
	}

	public function disconnect()
	{
		$this->api->setBaseUrl(null);
	}

	public function __call($method, $args)
	{
		return $this->call($method, isset($args[0]) ? $args[0] : array());
	}
	
	private $callCount = 0;
	

	public function call($method, $args)
	{
		$this->callCount++; 
		try {
			return $this->api->callMethod($method, $args);
		} catch (Zend_Http_Client_Exception $zce) {
		}
	}

	public static $methods = array(
		'getNode' => array(
			'url' => '/api/v1/{ClassName}/{ID}',
			'return' => 'dataobject'
		),
		'getChildren' => array(
			'url' => '/api/v1/{ClassName}',
			'params' => array('ParentID'),
			'return' => 'dataobjectset'
		),
		'getRelatedItems' => array(
			'url' => '/api/v1/{ClassName}/{ID}/{Relation}',
			'return' => 'dataobjectset'
		)
	);
}

class RemoteDataObjectHandler
{
	private $baseClass = 'SiteTree';
	private $remap = array(
		'ID' => 'SS_ID',
	);

	protected function getRemoteObject($node)
	{
		$clazz = $node->nodeName;
		$object = null;
		// do we have this data object type?
		if (ClassInfo::exists($clazz)) {
			// we'll create one
			$object = new $clazz;
		} else {
			$object = new SiteTree();
		}
		
		foreach ($node->childNodes as $property) {
			if ($property instanceof DOMText) {
				continue;
			}
			$pname = $property->nodeName;
			if (isset($this->remap[$pname])) {
				$pname = $this->remap[$pname];
			}
			
			$object->$pname = $property->nodeValue;
		}

		return $object;
	}
}

class DataObjectSetReturnHandler extends RemoteDataObjectHandler implements ReturnHandler
{
	public function handleReturn($raw)
	{
		$xml = new DomDocument();
		$raw = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $raw);
		$xml->loadXML($raw);
		$objects = new DataObjectSet();
		// lets get all the items beneath the root item
		foreach ($xml->childNodes as $node) {
			if ($node->nodeName == 'DataObjectSet') {
				foreach ($node->childNodes as $childNode) {
					if ($childNode instanceof DOMText) {
						continue;
					}

					$objects->push($this->getRemoteObject($childNode));
				}
			}
		}
		return $objects;
	}
}


class DataObjectReturnHandler extends RemoteDataObjectHandler implements ReturnHandler
{
	public function handleReturn($raw)
	{
		$xml = new DomDocument;
		$xml->loadXML($raw);
		$obj = $this->getRemoteObject($xml->childNodes->item(0));
		return $obj;
	}
}