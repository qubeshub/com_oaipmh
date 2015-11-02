<?php
/**
 * HUBzero CMS
 *
 * Copyright 2005-2015 HUBzero Foundation, LLC.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * HUBzero is a registered trademark of Purdue University.
 *
 * @package   hubzero-cms
 * @author    Shawn Rice <zooley@purdue.edu>
 * @copyright Copyright 2005-2015 HUBzero Foundation, LLC.
 * @license   http://opensource.org/licenses/MIT MIT
 */

namespace Components\Oaipmh\Models;

use Components\Oaipmh\Models\Xml\Response;
use Hubzero\Base\Traits\Escapable;
use Hubzero\Base\Object;
use Hubzero\Database\Driver;
use Exception;
use Event;
use Lang;

require_once(__DIR__ . '/../models/xml/response.php');

/**
 * OAIPMH Provider for building responses
 */
class Service extends Object
{
	use Escapable;

	/**
	 * OAI-PMH error types
	 */
	const ERROR_BAD_VERB             = 'badVerb';
	const ERROR_BAD_ARGUMENT         = 'badArgument';
	const ERROR_BAD_ID               = 'idDoesNotExist';
	const ERROR_BAD_FORMAT           = 'cannotDisseminateFormat';
	const ERROR_RECORD_NOT_FOUND     = 'noRecordsMatch';
	const ERROR_BAD_RESUMPTION_TOKEN = 'badResumptionToken';
	const ERROR_NO_SET_HIERARCHY     = 'noSetHierarchy';

	/**
	 * Schema to use
	 * 
	 * @var  object
	 */
	protected $schema = null;

	/**
	 * List of schemas
	 * 
	 * @var  array
	 */
	protected $schemas = null;

	/**
	 * XML Response
	 * 
	 * @var  object
	 */
	protected $response = null;

	/**
	 * List of providers
	 * 
	 * @var  array
	 */
	protected $providers = array();

	/**
	 * Database connection
	 * 
	 * @var  object
	 */
	protected $database = null;

	/**
	 * Constructor
	 *
	 * @param   string  $schema      Schema to use
	 * @param   string  $stylesheet  Optional stylesheet to link to
	 * @param   object  $db          Optional database connection
	 * @param   string  $version     XML Version
	 * @param   string  $encoding    XML Encoding
	 * @return  void
	 */
	public function __construct($schema=null, $stylesheet=null, $db=null, $version = '1.0', $encoding = 'utf-8')
	{
		$this->response = new Response($version, $encoding);
		if ($stylesheet)
		{
			$this->response->stylesheet((string) $stylesheet);
		}
		$this->response
			->element('OAI-PMH')
				->attr('xmlns', 'http://www.openarchives.org/OAI/2.0/')
				->attr('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance')
				->attr('xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd')
				->element('responseDate', gmdate('c', time()))->end();

		$this->loadSchemas();

		if ($schema)
		{
			$this->setSchema($schema);
		}

		$this->loadProviders();

		if (!$db)
		{
			$db = \App::get('db');
		}

		$this->setDbo($db);
	}

	/**
	 * Set the database connection
	 *
	 * @param   object  $db    Database connection
	 * @return  object  $this
	 */
	public function setDbo(Driver $db)
	{
		$this->database = $db;

		return $this;
	}

	/**
	 * Get the database connection
	 *
	 * @return  object
	 */
	public function getDbo()
	{
		return $this->database;
	}

	/**
	 * Load schemas
	 *
	 * @return  void
	 */
	private function loadSchemas()
	{
		foreach (glob(__DIR__ . DS . 'schemas' . DS . '*.php') as $schema)
		{
			require_once $schema;
		}

		$isSchemaClass = function($class)
		{
			return (in_array(__NAMESPACE__ . '\Schema', class_implements($class)));
		};

		$this->schemas = array_values(array_filter(get_declared_classes(), $isSchemaClass));
	}

	/**
	 * Get schema list
	 *
	 * @return  array
	 */
	public function getSchemas()
	{
		return $this->schemas;
	}

	/**
	 * Set schema
	 *
	 * @param   mixed   $type
	 * @return  object
	 * @throws  Exception
	 */
	public function setSchema($type)
	{
		if (!($type instanceof Schema))
		{
			$respondsTo = function($class) use ($type)
			{
				return $class::handles($type);
			};

			$responded = array_filter($this->schemas, $respondsTo);
			if ($schema = array_shift($responded))
			{
				$type = new $schema();
			}
		}

		$this->schema = $type;

		if (!$this->schema)
		{
			throw new Exception(Lang::txt('No schema handler found for schema "%s".', $this->schema));
		}

		$this->schema->setService($this);
		$this->schema->setResponse($this->response);

		return $this;
	}

	/**
	 * Get schema
	 *
	 * @return  object
	 */
	public function getSchema()
	{
		return $this->schema;
	}

	/**
	 * Set the response object
	 *
	 * @param   object  $response
	 * @return  object  $this
	 */
	public function setResponse(Response $response)
	{
		$this->response = $response;

		return $this;
	}

	/**
	 * Get XML builder
	 *
	 * @return  object
	 */
	public function getResponse()
	{
		return $this->response;
	}

	/**
	 * Load data providers
	 *
	 * Calls plugin event and passes self to
	 * the event method where plugins can
	 * register providers.
	 *
	 * @return  object
	 */
	private function loadProviders()
	{
		Event::trigger('oaipmh.onOaipmhProvider', array(&$this));

		return $this;
	}

	/**
	 * Register a provider
	 *
	 * @param   string  $key
	 * @param   object  $provider
	 * @return  object
	 */
	public function register($key, Provider $provider)
	{
		$this->providers[$key] = $provider;

		return $this;
	}

	/**
	 * Get the list of providers
	 *
	 * @return  array
	 */
	public function getProviders()
	{
		return $this->providers;
	}

	/**
	 * Set error
	 *
	 * @param   string  $error
	 * @param   string  $message
	 * @return  object
	 */
	public function error($error='', $message=null)
	{
		$this->response
			->element('request', $this->get('baseURL') . '/oaipmh')->end()
			->element('error', $message)
				->attr('code', ($error ?: self::ERROR_BAD_ARGUMENT))
			->end();

		return $this;
	}

	/**
	 * Identify the service
	 *
	 * @return  object
	 */
	public function identify()
	{
		$this->response
			->element('request', $this->get('baseURL') . '/oaipmh')
				->attr('verb', 'Identify')
			->end()
			->element('Identify');

		$fields = array(
			'repositoryName',
			'baseURL',
			'protocolVersion',
			'adminEmail',
			'earliestDatestamp',
			'deletedRecord',
			'granularity'
		);

		foreach ($fields as $key)
		{
			if ($val = $this->get($key))
			{
				$this->response->element($key, $val)->end();
			}
		}

		$this->response->end();

		return $this;
	}

	/**
	 * List formats
	 *
	 * @return  object
	 */
	public function formats()
	{
		$this->response
			->element('request', $this->get('repositoryName') . '/oaipmh')
				->attr('verb', 'ListMetadataFormats')
			->end()
			->element('ListMetadataFormats');

		foreach ($this->schemas as $schema)
		{
			$this->response
				->element('metadataFormat')
					->element('metadataPrefix', $schema::$prefix)->end()
					->element('schema', $schema::$schema)->end()
					->element('metadataNamespace', $schema::$ns)->end()
				->end();
		}

		$this->response->end();

		return $this;
	}

	/**
	 * List all sets
	 *
	 * @return  object
	 */
	public function sets()
	{
		$records = array();

		foreach ($this->providers as $provider)
		{
			if (!($query = $provider->sets()))
			{
				continue;
			}

			$this->database->setQuery($query);
			$sets = $this->database->loadRowList();
			foreach ($sets as $set)
			{
				array_push($records, $set);
			}
		}

		if (!count($records))
		{
			return $this->error(self::ERROR_NO_SET_HIERARCHY);
		}

		$this->response
			->element('request', $this->get('baseURL') . '/oaipmh')
				->attr('verb', 'ListSets')
			->end()
			->element('ListSets');

		$this->schema->sets($records);

		$this->response
			->end();

		return $this;
	}

	/**
	 * List only record headers
	 *
	 * @param   string  $from   Timestamp
	 * @param   string  $until  Timestamp
	 * @param   string  $set    Set to filter by
	 * @return  object  $this
	 */
	public function identifiers($from, $until, $set = null)
	{
		return $this->records($from, $until, $set, 'ListIdentifiers');
	}

	/**
	 * List records
	 *
	 * @param   string  $from   Timestamp
	 * @param   string  $until  Timestamp
	 * @param   string  $set    Set to filter by
	 * @param   string  $verb
	 * @return  object  $this
	 */
	public function records($from, $until, $set = null, $verb = 'ListRecords')
	{
		$queries = array();

		foreach ($this->providers as $provider)
		{
			if (!($query = $provider->records(array('from' => $from, 'until' => $until, 'set' => $set))))
			{
				continue;
			}

			$queries[] = $query;
		}

		if (!count($queries))
		{
			return $this->error(self::ERROR_RECORD_NOT_FOUND);
		}

		// Set flow control vars
		$limit      = $this->get('limit', 50);
		$start      = 0;
		$resumption = $this->get('resumption');

		// Check completion
		if (!empty($resumption))
		{
			$data = \App::get('session')->get($resumption);
			if (is_array($data))
			{
				if (!isset($data['prefix']) || $data['prefix'] != $this->get('metadataPrefix'))
				{
					//return $this->error(self::ERROR_BAD_RESUMPTION_TOKEN);
				}
				$start = (isset($data['start']) && isset($data['limit'])) ? $data['start'] + $data['limit']: $start;
			}
		}

		$resumption = md5(uniqid());

		\App::get('session')->set($resumption, array(
			'limit'  => $limit,
			'start'  => $start,
			'prefix' => $this->get('metadataPrefix')
		));

		// Get Records
		$sql = "SELECT COUNT(*) FROM (" . implode(' UNION ', $queries) . ") AS m";
		$this->database->setQuery($sql);
		$total = $this->database->loadResult();

		$sql = "SELECT m.* FROM (" . implode(' UNION ', $queries) . ") AS m LIMIT " . $start . "," . $limit;
		$this->database->setQuery($sql);
		$records = $this->database->loadObjectList();

		if (!count($records))
		{
			return $this->error(self::ERROR_RECORD_NOT_FOUND);
		}

		// Hook for post processing
		foreach ($this->providers as $provider)
		{
			$records = $provider->postRecords($records);
		}

		// Write schema
		$this->response
			->element('request', $this->get('baseURL') . '/oaipmh')
				->attr('verb', $verb)
			->end()
			->element($verb);

		$this->schema->records($records, ($verb == 'ListIdentifiers' ? false : true));

		// Write resumption token if needed
		if ($total > ($start + $limit))
		{
			$this->response
				->element('resumptionToken', $resumption)
					->attr('completeListSize', $total)
					->attr('cursor', ($start + $limit))
				->end();
		}

		$this->response
			->end();

		return $this;
	}

	/**
	 * Process a single record
	 *
	 * @param   string  $identifier
	 * @return  object  $this
	 */
	public function record($identifier)
	{
		$result = null;

		foreach ($this->providers as $provider)
		{
			if ($id = $provider->match($identifier))
			{
				$result = $provider->record($id);
				break;
			}
		}

		if (!$result)
		{
			return $this->error(self::ERROR_RECORD_NOT_FOUND);
		}

		$this->response
			->element('request', $this->get('baseURL') . '/oaipmh')
				->attr('verb', 'GetRecord')
				->attr('identifier', $identifier)
				->attr('metadataPrefix', $this->get('metadataPrefix'))
			->end()
			->element('GetRecord');

		$this->schema->record($result);

		$this->response
			->end();

		return $this;
	}

	/**
	 * Get the final response as XML
	 *
	 * @return  string
	 */
	public function xml()
	{
		return $this->response->end()->getXml(true);
	}

	/**
	 * Return the XML response
	 *
	 * @return  string
	 */
	public function __toString()
	{
		return $this->xml();
	}
}
