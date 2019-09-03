<?php
declare(strict_types=1);

namespace Bigstep\LockInnoDB\Engines
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


		public function __construct()
		{
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

				default:
					throw new \Exception("'".$propertyName."' property doesn't exist.");
			}
		}
	}
}