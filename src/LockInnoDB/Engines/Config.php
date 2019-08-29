<?php
declare(strict_types=1);

namespace LockInnoDB\Engines
{
	class Config
	{
		/**
		 * @var string
		 */
		public $databaseName = "";


		/**
		 * @var int
		 */
		public $port = 3306;


		/**
		 * @var string
		 */
		public $host = "";


		/**
		 * @var string
		 */
		public $username = "";


		/**
		 * @var string
		 */
		public $password = "";


		/**
		 * @var string
		 */
		public $logsPath = "";


		/**
		 * @var string
		 */
		public $lock = "";


		/**
		 * @var string
		 */
		public $cache = "";


		/**
		 * @var string
		 */
		public $upgraderInfo = "";


		/**
		 * @var bool
		 */
		public $isInProduction = false;


		/**
		 * @var int
		 */
		public $minInnoDBBufferPoolSize = 0;


		/**
		 * @var int
		 */
		public $maxHeapTableSize = 0;


		/**
		 * @var string
		 */
		public $transactionIsolationLevel = "";


		/**
		 * Optional
		 * 
		 * @var bool
		 */
		public $enableDebug = false;


		/**
		 * Optional
		 * 
		 * @var bool
		 */
		public $enableQueryComments = false;


		/**
		 * Optional
		 * 
		 * @var function
		 */
		public $afterUpgradeCallback = null;


		/**
		 * Allowed transaction isolation levels.
		 * 
		 * @var array
		 */
		protected static $_isolationLevels = [
			"REPEATABLE READ",
			"READ COMMITTED",
			"READ UNCOMMITTED",
			"SERIALIZABLE"
		];


		public function __construct()
		{
			$afterUpgradeCallback = function(){};
		}


		/**
		 * Checks if a property exists and if it has the required data type.
		 * 
		 * @param string $propertyName
		 * 
		 * @throws \Exception
		 */
		public function validate(string $propertyName):void
		{
			switch ($propertyName) {
				case "databaseName":
					if(!is_string($this->databaseName))
					{
						throw new \Exception("'databaseName' property must be of type string.");
					}
					break;

				case "port":
					if(!is_int($this->port))
					{
						throw new \Exception("'port' property must be of type int.");
					}
					break;

				case "host":
					if(!is_string($this->host))
					{
						throw new \Exception("'host' property must be of type string.");
					}
					break;

				case "username":
					if(!is_string($this->username))
					{
						throw new \Exception("'username' property must be of type string.");
					}
					break;

				case "password":
					if(!is_string($this->password))
					{
						throw new \Exception("'password' property must be of type string.");
					}
					break;

				case "logsPath":
					if(!is_string($this->logsPath))
					{
						throw new \Exception("'logsPath' property must be of type string.");
					}
					break;

				case "lock":
					if(!is_string($this->lock))
					{
						throw new \Exception("'lock' property must be of type string.");
					}
					break;

				case "cache":
					if(!is_string($this->cache))
					{
						throw new \Exception("'cache' property must be of type string.");
					}
					break;

				case "upgraderInfo":
					if(!is_string($this->upgraderInfo))
					{
						throw new \Exception("'upgraderInfo' property must be of type string.");
					}
					break;

				case "isInProduction":
					if(!is_bool($this->isInProduction))
					{
						throw new \Exception("'isInProduction' property must be of type bool.");
					}
					break;

				case "minInnoDBBufferPoolSize":
					if(!is_int($this->minInnoDBBufferPoolSize))
					{
						throw new \Exception("'minInnoDBBufferPoolSize' property must be of type int.");
					}
					break;

				case "maxHeapTableSize":
					if(!is_int($this->maxHeapTableSize))
					{
						throw new \Exception("'maxHeapTableSize' property must be of type int.");
					}
					break;

				case "transactionIsolationLevel":
					if(!is_string($this->transactionIsolationLevel))
					{
						throw new \Exception("'transactionIsolationLevel' property must be of type string.");
					}
					else
					{
						if(!in_array($this->transactionIsolationLevel, static::$_isolationLevels))
						{
							throw new \Exception(
								"MySQL transaction isolation level must be: ".json_encode(static::$_isolationLevels).".
								Value ".json_encode($this->transactionIsolationLevel)." is not accepted!"
							);
						}
					}
					break;

				case "enableDebug":
					if(!is_bool($this->enableDebug))
					{
						throw new \Exception("'enableDebug' property must be of type bool.");
					}
					break;

				case "enableQueryComments":
					if(!is_bool($this->enableQueryComments))
					{
						throw new \Exception("'enableQueryComments' property must be of type bool.");
					}
					break;

				default:
					throw new \Exception("'".$propertyName."' property doesn't exist.");
			}
		}
	}
}