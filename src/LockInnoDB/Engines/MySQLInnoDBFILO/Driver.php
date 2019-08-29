<?php
declare(strict_types=1);

namespace LockInnoDB\Engines\MySQLInnoDBFILO
{
	use \LockInnoDB\Exceptions\LockException;
	use \LockInnoDB\Engines\DriverBase;
	use \LockInnoDB\Exceptions\CustomPDOException;
	use \LockInnoDB\Engines\Config;


	/**
	 * DANGER:
	 * 
	 * 1. Successive acquire and release calls are expected to be FILO (first in, last out).
	 * When releasing a lock, all locks acquired after it are also released in MySQL (but are kept on disk; this class extends the FileSystem lock).
	 *
	 * Long running processes which use a lock to prevent multiple instances of the same process (service) on the same machine must not use this driver.
	 * The FileSystem driver should be used instead.
	 * 
	 * 2. ROLLBACK TO SAVEPOINT does NOT work in MySQL 8.0.x (most recent test was on MySQL 8.0.17)
	 * https://bugs.mysql.com/bug.php?id=96518
	 */
	class Driver extends DriverBase
	{
		/**
		 * @var \PDO
		 */
		protected $_pdo = null;


		/**
		 * @var \PDO
		 */
		protected $_pdoMetadata = null;


		/**
		 * Required parameters for MySQL configuration
		 * 
		 * @var \LockInnoDB\Engines\Config
		 */
		protected $_mysqlConfig;


		/**
		 * @var \LockInnoDB\Engines\DriverBase
		 */
		protected $_lockWrapper;


		/**
		 * @var array
		 */
		protected $_locksStack = [];


		/**
		 * @var array
		 */
		protected $_lockNamesToSavePoints = [];


		/**
		 * @var int
		 */
		protected $_savePointIncrement = 0;


		/**
		 * @var bool
		 */
		protected $_isStrictFILOAcquireRelease;


		/**
		 * @var int
		 */
		protected $_MySQLConnectionID = null;



		/**
		 * Do not turn off $isStrictFILOAcquireRelease unless you understand how ROLLBACK TO SAVEPOINT works with multiple save points.
		 *
		 * $lockWrapper can be used to add multiple locking places and mechanism for increased safety or performance.
		 *
		 * @param \LockInnoDB\Engines\DriverBase $lockWrapper = null
		 * @param array $config
		 * @param bool $isStrictFILOAcquireRelease = true
		 * 
		 * @throws \LockInnoDB\Exceptions\CustomPDOException
		 */
		public function __construct(\LockInnoDB\Engines\DriverBase $lockWrapper = null, array $config, bool $isStrictFILOAcquireRelease = true)
		{
			if(!array_key_exists("maxHeapTableSize", $config))
			{
				throw new \LockInnoDB\Exceptions\CustomPDOException("'maxHeapTableSize' property is mandatory in mysql config.");
			}

			if(is_null($lockWrapper))
			{
				$lockWrapper = new \LockInnoDB\Engines\NoLocking\Driver();
			}

			$this->_lockWrapper = $lockWrapper;

			$this->_isStrictFILOAcquireRelease = $isStrictFILOAcquireRelease;

			$this->_mysqlConfig = new \LockInnoDB\Engines\Config();

			foreach($config as $propertyName => $propertyValue)
			{
				$this->_mysqlConfig->$propertyName = $propertyValue;
				$this->_mysqlConfig->validate($propertyName);
			}
		}


		/**
		 * Acquires a new lock if it isn't used currently.
		 * 
		 * @param string $lockName
		 * @param bool $isBlocking
		 * @param int $nonBlockingLockRetries = 0
		 * @param int $waitSecondsBeforeRetry = self::NON_BLOCKING_LOCK_WAIT_BEFORE_RETRY_SECONDS_DEFAULT
		 * 
		 * @throws \LockInnoDB\Exceptions\LockException
		 * @throws \Throwable
		 */
		public function acquire(string $lockName, bool $isBlocking, int $nonBlockingLockRetries=0, int $waitSecondsBeforeRetry=self::NON_BLOCKING_LOCK_WAIT_BEFORE_RETRY_SECONDS_DEFAULT):void
		{
			if(in_array($lockName, $this->_locksStack))
			{
				if($isBlocking)
				{
					throw new \LockInnoDB\Exceptions\LockException("Non-blocking MySQL lock ".json_encode($lockName).". ".$this->_processInfo(/*isReadAcquirer*/ true, $lockName), \LockInnoDB\Exceptions\LockException::NON_BLOCKING_LOCK);
				}
				
				// Compatibility with the FileSystem lock and disaster prevention.
				throw new \LockInnoDB\Exceptions\LockException("Deadlock detected. Thread tried to acquire the same MySQL lock (".json_encode($lockName).") without releasing first. ".$this->_processInfo(), \LockInnoDB\Exceptions\LockException::DEADLOCK);
			}


			$this->_lockWrapper->acquire($lockName, $isBlocking, $nonBlockingLockRetries, $waitSecondsBeforeRetry);

			try
			{
				$pdo = $this->_pdo();
				$pdoMetadata = $this->_pdoMetadata();


				$this->_lockNamesToSavePoints["L".$lockName] = "S".$this->_savePointIncrement++;
				$pdo->exec("SAVEPOINT `".$this->_lockNamesToSavePoints["L".$lockName]."`");


				$pdo->exec("SET SESSION innodb_lock_wait_timeout=" . ($isBlocking ? 1073741824 : 1));

				while(true)
				{
					try
					{
						$pdo->exec("REPLACE INTO `locks` SET `lock_name` = ".$pdo->quote($lockName));

						// By convention, only write after acquiring the lock above.
						$pdoMetadata->exec("
							REPLACE INTO `locks_metadata`
							SET
								`lock_name` = ".$pdo->quote($lockName).",
								`lock_acquire_timestamp` = CONCAT(REPLACE(NOW(), ' ', 'T'), 'Z'),
								`lock_is_exclusive` = ".(int)$isBlocking.",
								`lock_mysql_connection_id` = ".(int)$pdo->query("SELECT CONNECTION_ID()")->fetchColumn().",
								`lock_acquirer_pid` = ".(int)getmypid().",
								`lock_acquirer_hostname` = ".$pdo->quote(gethostname()).",
								`lock_acquirer_app_trace` = ".$pdo->quote(\LockInnoDB\Utils\LockUtils::getTraceAsStringWithoutParams(new \Exception()))."
						");

						break;
					}
					catch(\PDOException $exc)
					{
						try
						{
							// Just in case the thrown error handling mechanism does not roll it back and then tries to acquire the same lock on a new fresh connection.
							$this->_pdo()->exec("ROLLBACK TO SAVEPOINT `".$this->_lockNamesToSavePoints["L".$lockName]."`");
						}
						catch(\Throwable $excSafetyRollback)
						{
							// Has to be ignored because the reason for failing might be, for example, MySQL has gone away!
							error_log("Lock handled error of MySQL just in case rollback after lock acquire error: ".$excSafetyRollback->getMessage()." ".$excSafetyRollback->getTraceAsString()." ".$this->_processInfo());
						}

						if(
							!$isBlocking
							&& $exc->errorInfo[0]=="HY000" /* General error */
							&& $exc->errorInfo[1]==1205 /*(MySQL SQLSTATE) ER_LOCK_WAIT_TIMEOUT*/
						)
						{
							if(--$nonBlockingLockRetries >= 0)
							{
								sleep($waitSecondsBeforeRetry);
								continue;
							}
							else
							{
								throw new \LockInnoDB\Exceptions\LockException("Non-blocking MySQL lock ".json_encode($lockName).". ".$this->_processInfo(/*isReadAcquirer*/ true, $lockName), \LockInnoDB\Exceptions\LockException::NON_BLOCKING_LOCK, $exc);
							}
						}


						if(
							$exc->errorInfo[0]==40001 /*(ISO/ANSI) Serialization failure, e.g. timeout or deadlock*/
							&& $exc->errorInfo[1]==1213  /*(MySQL SQLSTATE) ER_LOCK_DEADLOCK*/
						)
						{
							throw new \LockInnoDB\Exceptions\LockException("MySQL deadlock ".json_encode($lockName).". ".$exc->getMessage()." ".$this->_processInfo(), \LockInnoDB\Exceptions\LockException::DEADLOCK, $exc);
						}


						throw $exc;
					}
				}


				$this->_locksStack[] = $lockName;
			}
			catch(\Throwable $exc)
			{
				$this->_lockWrapper->release($lockName);

				throw $exc;
			}
		}


		/**
		 * Release the specified lock.
		 * Throws error if $_isStrictFILOAcquireRelease is true and the release isn't FILO.
		 * 
		 * @param string $lockName
		 * 
		 * @throws \LockInnoDB\Exceptions\LockException
		 */
		public function release(string $lockName):void
		{
			$this->_pdoMetadata()->exec("DELETE FROM `locks_metadata` WHERE `lock_name` = ".$this->_pdoMetadata()->quote($lockName));

			$stackCount = count($this->_locksStack);
			assert(!$this->_isStrictFILOAcquireRelease || $stackCount);

			for($i = $stackCount-1; $i >= 0; $i--)
			{
				if($this->_locksStack[$i] === $lockName)
				{
					$InnoDBRolledBackSavePoints = array_slice($this->_locksStack, $i);

					if(
						$this->_isStrictFILOAcquireRelease
						&& count($InnoDBRolledBackSavePoints) > 1
					)
					{
						throw new \LockInnoDB\Exceptions\LockException(
							(
								"Cannot release the MySQL ".json_encode($lockName)." lock until subsequent acquired locks are intentionally released before it"
								." due to InnoDB SAVEPOINT rollback limitations. First in (acquire), last out (release). Locks: ".json_encode($this->_locksStack, JSON_PRETTY_PRINT)." ".$this->_processInfo()
							),
							\LockInnoDB\Exceptions\LockException::LOCK_INTEGRITY_FAILED
						);
					}

					array_splice($this->_locksStack, $i);

					break;
				}
			}

			if(!count($this->_locksStack))
			{
				$this->_pdo()->exec("ROLLBACK");
				$this->_pdo()->exec("BEGIN");
			}
			else
			{
				$this->_pdo()->exec("ROLLBACK TO SAVEPOINT `".$this->_lockNamesToSavePoints["L".$lockName]."`");
			}

			$this->_lockWrapper->release($lockName);
		}


		/**
		 * Remove unused locks.
		 * This function should be called by cron.
		 */
		public function unusedLocksRemove():void
		{
			$this->_lockWrapper->unusedLocksRemove();

			// Transactions are never committed, so the table is expected to always be empty.
		}


		/**
		 * Should be used by long running processes in their main loop or at key execution points, at an acceptable interval (to not flood MySQL).
		 *
		 * @param string $lockName = null
		 * 
		 * @throws \Exception
		 * @throws \LockInnoDB\Exceptions\LockException
		 */
		public function assertConnected(string $lockName = null):void
		{
			try
			{
				$this->_pdo()->query("SELECT 1");
			}
			catch(\PDOException $exc)
			{
				throw new \LockInnoDB\Exceptions\LockException($exc->getMessage()." ".$this->_processInfo(), \LockInnoDB\Exceptions\LockException::LOCK_INTEGRITY_FAILED, $exc);
			}
		}


		/**
		 * Disconnect from MySQL, after rollback.
		 */
		public function disconnect():void
		{
			if(!is_null($this->_pdo))
			{
				$this->_pdo->exec("ROLLBACK");
				$this->_pdo = NULL;
			}
		}


		/**
		 * Releases all locks and closes MySQL connection.
		 */
		public function releaseAll():void
		{
			try
			{
				$this->disconnect();
			}
			finally
			{
				$this->_lockWrapper->disconnect();
			}
		}


		/**
		 * @return string
		 */
		public function getLocksPath():string
		{
			return $this->_lockWrapper->getLocksPath();
		}


		/**
		 * @return \PDO
		 */
		protected function _pdo():\PDO
		{
			if(is_null($this->_pdo))
			{
				// Don't use persistent connections, "to be reused connections" also reuse the MySQL session 
				// which results in locked rows not getting released and uncommited transactions would be refused.

				$this->_pdo = new \PDO(
					"mysql:host=".$this->_mysqlConfig->host.";port=".$this->_mysqlConfig->port.";dbname=".$this->_mysqlConfig->databaseName.";",
					$this->_mysqlConfig->username,
					$this->_mysqlConfig->password,
					[
						\PDO::ATTR_PERSISTENT=>false
					]
				);

				$this->_MySQLConnectionID = (int)$this->_pdo->query("SELECT CONNECTION_ID();")->fetchColumn();

				$this->_pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
				$this->_pdo->setAttribute(\PDO::ATTR_PERSISTENT, false);

				$this->_pdo->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
				//$this->_pdo->exec("SET time_zone='+00:00';"); // UTC
				$this->_pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES';"); // strict mode


				// Reset to default.
				$this->_pdo->exec("SET SESSION lock_wait_timeout = 31536000");


				// The only safe value for a locking system.
				try
				{
					// Seconds of lack of activity until the connection is closed.
					$this->_pdo->exec("SET SESSION wait_timeout = 31536000"); // 365 days
				}
				catch(\PDOException $pdoException)
				{
					// https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_wait_timeout
					// MySQL running on Windows, limits wait_timeout at 2147483 (~24 days), which sucks for locking purposes.
					$this->_pdo->exec("SET SESSION wait_timeout = 2147483"); // 24 days
				}


				assert(!count($this->_pdo->query("SHOW TABLE STATUS WHERE `Name`='locks' AND `Engine` <> 'InnoDB'")->fetchAll(\PDO::FETCH_ASSOC)), "Only the InnoDB table engine is supported.");

				$this->_pdo->exec("ROLLBACK"); // clear persistent connection left overs.
				$this->_pdo->exec("BEGIN");
			}

			return $this->_pdo;
		}


		/**
		 * @return \PDO
		 */
		protected function _pdoMetadata():\PDO
		{
			if(is_null($this->_pdoMetadata))
			{
				// Don't use persistent connections, "to be reused connections" also reuse the MySQL session 
				// which results in locked rows not getting released and uncommited transactions would be refused.
				
				$this->_pdoMetadata = new \PDO(
					"mysql:host=".$this->_mysqlConfig->host.";port=".$this->_mysqlConfig->port.";dbname=".$this->_mysqlConfig->databaseName.";",
					$this->_mysqlConfig->username,
					$this->_mysqlConfig->password,
					[
						\PDO::ATTR_PERSISTENT=>false
					]
				);

				$this->_pdoMetadata->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
				$this->_pdoMetadata->setAttribute(\PDO::ATTR_PERSISTENT, false);

				$this->_pdoMetadata->exec("SET time_zone='+00:00';"); // UTC
				$this->_pdoMetadata->exec("SET sql_mode = 'STRICT_ALL_TABLES';"); // strict mode

				// How long to wait after locked rows.
				$this->_pdoMetadata->exec("SET SESSION lock_wait_timeout = 10");

				
				try
				{
					// Seconds of lack of activity until the connection is closed.
					$this->_pdoMetadata->exec("SET SESSION wait_timeout = 31536000"); // 365 days
				}
				catch(\PDOException $pdoException)
				{
					// https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_wait_timeout
					// MySQL running on Windows, limits wait_timeout at 2147483 (~24 days), which sucks for locking purposes.
					$this->_pdoMetadata->exec("SET SESSION wait_timeout = 2147483"); // 24 days
				}
			}

			return $this->_pdoMetadata;
		}


		private function _processInfo(bool $isReadAcquirer = false, string $lockName = ""):string
		{
			$processInfo = "Hostname: ".gethostname().". PID: ".getmypid()." MySQL host: ".$this->_mysqlConfig->host." MySQL connection ID:".json_encode($this->_MySQLConnectionID);
			
			try
			{
				if($isReadAcquirer)
				{
					assert(strlen($lockName), "lockName is mandatory when isReadAcquirer is true.");

					$pdoMetadata = $this->_pdoMetadata();
					$lockHolderInfo = $pdoMetadata->query("
						SELECT
							lock_acquire_timestamp,
							lock_is_exclusive,
							lock_mysql_connection_id,
							lock_acquirer_pid,
							lock_acquirer_hostname,
							lock_acquirer_app_trace
						FROM `locks_metadata`
						WHERE
							lock_name = ".$pdoMetadata->quote($lockName)."
					")->fetch(\PDO::FETCH_ASSOC);

					if($lockHolderInfo !== false)
					{
						$processInfo .= 
							PHP_EOL
							."***Lock holder***: "
							.str_replace("\"", "", json_encode($lockHolderInfo, JSON_PRETTY_PRINT))
						;
					}
				}
			}
			catch(\Throwable $exc)
			{
				error_log($exc->getMessage()." ".$exc->getTraceAsString());
			}
			
			return $processInfo;
		}
	}
}
