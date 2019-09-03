<?php
declare(strict_types=1);
declare(ticks=1000);

namespace LockInnoDB\Engines\MySQLInnoDB
{
	use \LockInnoDB\Exceptions\LockException;
	use \LockInnoDB\Engines\DriverBase;
	use \LockInnoDB\Exceptions\CustomPDOException;
	use \LockInnoDB\Engines\Config;


	class Driver extends DriverBase
	{
		/**
		 * Each lock has its own MySQL connection.
		 * 
		 * @var array
		 */
		protected $_lockPDOConnections = [];


		/**
		 * Separate connection to write a description of each lock like acquirer (PID, hostname, time, etc.).
		 * 
		 * @var array
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
		 * An array containing all the acquired locks names in order.
		 * 
		 * @var array
		 */
		protected $_lockAcquisitionOrder = [];


		/**
		 * @var int[]
		 */
		protected $_lockToAgeSeconds = [];


		/**
		 * The timeout is synchronized to MySQL's timeout to know when it becomes unsafe to keep running this process,
		 * and take an action such as aborting execution of the entire PHP process.
		 * 
		 * @var bool
		 */
		protected $_isTimeoutSet = false;


		/**
		 * MySQL TTL per locked row.
		 * 
		 * @var int
		 */
		protected $_MySQLMaxWaitTimeout = 31536000; // 365 days, the maximum value allowed by MySQL.


		/**
		 * Lock name to MySQL session connection ID.
		 * 
		 * @var array
		 */
		protected $_MySQLConnectionIDs = [];



		/**
		 * $lockWrapper can be used to add multiple locking places and mechanism for increased safety or performance.
		 *
		 * @param \LockInnoDB\Engines\DriverBase $lockWrapper = null
		 * @param array $config
		 * @param bool $isStrictFILOAcquireRelease = true
		 */
		public function __construct(\LockInnoDB\Engines\DriverBase $lockWrapper = null, array $config)
		{
			if(is_null($lockWrapper))
			{
				$lockWrapper = new \LockInnoDB\Engines\NoLocking\Driver();
			}

			$this->_lockWrapper = $lockWrapper;

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
			if(in_array($lockName, $this->_lockAcquisitionOrder))
			{
				if($isBlocking)
				{
					throw new \LockInnoDB\Exceptions\LockException("Non-blocking MySQL lock ".json_encode($lockName).". ".$this->_processInfo(/*isReadAcquirer*/ true, $lockName), \LockInnoDB\Exceptions\LockException::NON_BLOCKING_LOCK);
				}

				// Compatibility with the FileSystem lock and disaster prevention.
				throw new \LockInnoDB\Exceptions\LockException("Deadlock detected. Thread tried to acquire the same MySQL lock (".json_encode($lockName).") without releasing first. ".$this->_processInfo(false, $lockName), \LockInnoDB\Exceptions\LockException::DEADLOCK);
			}


			$this->_lockWrapper->acquire($lockName, $isBlocking, $nonBlockingLockRetries, $waitSecondsBeforeRetry);

			try
			{
				$processExecutionSeconds = max(0, time() - $_SERVER["REQUEST_TIME"]);

				$this->_lockToAgeSeconds[$lockName] = $processExecutionSeconds;

				$this->_lockPDOConnections[$lockName] = $this->_pdo($lockName);

				$this->_lockAcquisitionOrder[] = $lockName;

				$this->_lockPDOConnections[$lockName]->exec("SET SESSION innodb_lock_wait_timeout=" . ($isBlocking ? 1073741824 : 1));

				while(true)
				{
					try
					{
						$this->_lockPDOConnections[$lockName]->exec("REPLACE INTO `locks` SET `lock_name` = ".$this->_lockPDOConnections[$lockName]->quote($lockName));

						// By convention, only write after acquiring the lock above.
						$this->_pdoMetadata()->exec("
							REPLACE INTO `locks_metadata`
							SET
								`lock_name` = ".$this->_pdoMetadata()->quote($lockName).",
								`lock_acquire_timestamp` = CONCAT(REPLACE(NOW(), ' ', 'T'), 'Z'),
								`lock_is_exclusive` = ".(int)$isBlocking.",
								`lock_mysql_connection_id` = ".(int)$this->_MySQLConnectionIDs[$lockName].",
								`lock_acquirer_pid` = ".(int)getmypid().",
								`lock_acquirer_hostname` = ".$this->_lockPDOConnections[$lockName]->quote(gethostname()).",
								`lock_acquirer_app_trace` = ".$this->_lockPDOConnections[$lockName]->quote(\LockInnoDB\Utils\LockUtils::getTraceAsStringWithoutParams(new \Exception()))."
						");

						break;
					}
					catch(\PDOException $exc)
					{
						try
						{
							// Just in case the thrown error handling mechanism does not roll it back and then tries to acquire the same lock on a new fresh connection.
							$this->_lockPDOConnections[$lockName]->exec("ROLLBACK");
						}
						catch(\Throwable $excSafetyRollback)
						{
							// Has to be ignored because the reason for failing might be, for example, MySQL has gone away!
							error_log("Lock handled error of MySQL just in case rollback after lock acquire error: ".$excSafetyRollback->getMessage()." ".$excSafetyRollback->getTraceAsString()." ".$this->_processInfo(false, $lockName));
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
							throw new \LockInnoDB\Exceptions\LockException("MySQL deadlock ".json_encode($lockName).". ".$exc->getMessage()." ".$this->_processInfo(false, $lockName), \LockInnoDB\Exceptions\LockException::DEADLOCK, $exc);
						}


						throw $exc;
					}
				}
			}
			catch(\PDOException $exc)
			{
				$this->_lockWrapper->release($lockName);

				throw $exc;
			}
		}


		/**
		 * Release the specified lock.
		 * 
		 * @param string $lockName
		 * 
		 * @throws \Throwable
		 * @throws \PDOException
		 * @throws \LockInnoDB\Exceptions\LockException
		 */
		public function release(string $lockName):void
		{
			try
			{
				$this->assertConnected($lockName);

				$this->_lockPDOConnections[$lockName]->exec("ROLLBACK");
			}
			catch(\LockInnoDB\LockException $exc)
			{
				error_log($exc->getMessage()." ".$exc->getTraceAsString());
			}
			catch(\PDOException $exc)
			{
				error_log($exc->getMessage()." ".$exc->getTraceAsString());
			}

			try
			{
				$this->_pdoMetadata()->exec("DELETE FROM `locks_metadata` WHERE `lock_name` = ".$this->_pdoMetadata()->quote($lockName));

				$key = array_search($lockName, $this->_lockAcquisitionOrder);

				if($key !== false)
				{
					assert(is_int($key));

					array_splice($this->_lockAcquisitionOrder, $key, 1);
					array_splice($this->_lockToAgeSeconds, $key, 1);

					if($key === 0 && $this->_isTimeoutSet)
					{
						unregister_tick_function([$this, "_checkIfTimeout"]);

						$this->_isTimeoutSet = false;
					}

					if(!empty($this->_lockToAgeSeconds) && !$this->_isTimeoutSet)
					{
						register_tick_function([$this, "_checkIfTimeout"]);

						$this->_isTimeoutSet = true;
					}
				}

				$this->_lockPDOConnections[$lockName] = null;

				unset($this->_lockPDOConnections[$lockName]);
				unset($this->MySQLConnectionIDs[$lockName]);
			}
			finally
			{
				$this->_lockWrapper->unusedLocksRemove();
			}
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
		 * @param string $lockName
		 *
		 * @throws \Exception
		 * @throws \LockInnoDB\Exceptions\LockException
		 */
		public function assertConnected(string $lockName):void
		{
			try
			{
				if($lockName === null || !array_key_exists($lockName, $this->_lockPDOConnections))
				{
					throw new \Exception("Couldn't find lock: '".$lockName."' in _lockPDOConnections");
				}

				$this->_lockPDOConnections[$lockName]->query("SELECT 1");
			}
			catch(\PDOException $exc)
			{
				throw new \LockInnoDB\Exceptions\LockException($exc->getMessage()." ".$this->_processInfo(false, $lockName), \LockInnoDB\Exceptions\LockException::LOCK_INTEGRITY_FAILED, $exc);
			}
		}


		/**
		 * Disconnect from MySQL, after rollback.
		 * 
		 * @throws \Throwable
		 */
		public function disconnect():void
		{
			if($this->_isTimeoutSet)
			{
				unregister_tick_function([$this, "_checkIfTimeout"]);

				$this->_isTimeoutSet = false;
			}

			$exceptions = [];

			for($i = count($this->_lockAcquisitionOrder) - 1; $i >= 0; $i--)
			{
				try
				{
					try
					{
						$this->assertConnected($this->_lockAcquisitionOrder[$i]);

						$this->_pdoMetadata()->exec("DELETE FROM `locks_metadata` WHERE `lock_name` = ".$this->_pdoMetadata()->quote($this->_lockAcquisitionOrder[$i]));
						$this->_lockPDOConnections[$this->_lockAcquisitionOrder[$i]]->exec("ROLLBACK");

						$this->_lockPDOConnections[$this->_lockAcquisitionOrder[$i]] = null;

						unset($this->_lockPDOConnections[$this->_lockAcquisitionOrder[$i]]);
						unset($this->_MySQLConnectionIDs[$this->_lockAcquisitionOrder[$i]]);

						array_splice($this->_lockAcquisitionOrder, $i, 1);
						array_splice($this->_lockToAgeSeconds, $i, 1);
					}
					catch(\LockInnoDB\Exceptions\LockException $exc)
					{
						if($exc->getCode() === \LockInnoDB\Exceptions\LockException::LOCK_INTEGRITY_FAILED)
						{
							// We're trying to disconnect all locks and all MySQL connections.
							// So it is OK to just log the errors about being no longer connected and such. Except some connectivity issues might get by in this particular instance 
							// (code should rarely call disconnect(), like only when retrying an entire operation at process level - in which case things went terribly wrong already; 
							// like a MySQL restart while running things, and it would be nice for the retry to be able to meet its purpose for being.).
							error_log($exc->getMessage()." ".$exc->getTraceAsString());
						}
						else
						{
							throw $exc;
						}
					}
				}
				catch(\PDOException $exc)
				{
					// Avoid last catch block.
					error_log($exc->getMessage()." ".$exc->getTraceAsString());
				}
				catch(\LockInnoDB\Exceptions\LockException $exc)
				{
					// Avoid last catch block.
					error_log($exc->getMessage()." ".$exc->getTraceAsString());
				}
				catch(\Throwable $exc)
				{
					// Try to release as many connections and locks as possible, not throwing early.
					// Throw as late as possible, outside this iteration.
					$exceptions[] = $exc;
				}
			}

			$this->_pdoMetadata = null;

			if(count($exceptions))
			{
				throw $exceptions[0];
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
		 * @param string $lockName
		 * 
		 * @return \PDO
		 */
		protected function _pdo(string $lockName):\PDO
		{
			// Don't use persistent connections, "to be reused connections" also reuse the MySQL session 
			// which results in locked rows not getting released and uncommited transactions would be refused.

			if(!array_key_exists($lockName, $this->_lockPDOConnections))
			{
				$pdo = new \PDO(
					"mysql:host=".$this->_mysqlConfig->host.";port=".$this->_mysqlConfig->port.";dbname=".$this->_mysqlConfig->databaseName.";",
					$this->_mysqlConfig->username,
					$this->_mysqlConfig->password,
					[
						\PDO::ATTR_PERSISTENT=>false
					]
				);

				$this->_MySQLConnectionIDs[$lockName] = (int)$pdo->query("SELECT CONNECTION_ID();")->fetchColumn();

				$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
				$pdo->setAttribute(\PDO::ATTR_PERSISTENT, false);

				$pdo->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
				//$pdo->exec("SET time_zone='+00:00';"); // UTC
				$pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES';"); // strict mode


				// Reset to default.
				$pdo->exec("SET SESSION lock_wait_timeout = 31536000");

				// https://www.php.net/manual/ro/function.set-time-limit.php
				// https://www.drupal.org/project/job_queue/issues/679324
				// When called, set_time_limit() restarts the timeout counter from zero. In other words, if the timeout is the default 30 seconds, and 25 seconds into script execution a call such as set_time_limit(20) is made, the script will run for a total of 45 seconds before timing out.
				// Reminder to not rely on set_time_limit() or ini_get("max_execution_time).


				// The only safe value for a locking system.
				try
				{
					// Seconds of lack of activity until the connection is closed.
					$pdo->exec("SET SESSION wait_timeout = 31536000"); // 365 days.

					if(!$this->_isTimeoutSet)
					{
						$this->_MySQLMaxWaitTimeout = 31536000;

						register_tick_function([$this, "_checkIfTimeout"]);

						$this->_isTimeoutSet = true;
					}
				}
				catch(\PDOException $exc)
				{
					// https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_wait_timeout
					// MySQL running on Windows, limits wait_timeout at 2147483 (~24 days), which sucks for locking purposes.
					$pdo->exec("SET SESSION wait_timeout = 2147483"); // 24 days

					if(!$this->_isTimeoutSet)
					{
						$this->_MySQLMaxWaitTimeout = 2147483;

						register_tick_function([$this, "_checkIfTimeout"]);

						$this->_isTimeoutSet = true;
					}
				}


				assert(!count($pdo->query("SHOW TABLE STATUS WHERE `Name`='locks' AND `Engine` <> 'InnoDB'")->fetchAll(\PDO::FETCH_ASSOC)), "Only the InnoDB table engine is supported.");

				$pdo->exec("ROLLBACK"); // clear persistent connection left overs.
				$pdo->exec("BEGIN");

				return $pdo;
			}
			else
			{
				return $this->_lockPDOConnections[$lockName];
			}
		}


		/**
		 * @return \PDO
		 * 
		 * @throws \Throwable
		 */
		protected function _pdoMetadata():\PDO
		{
			// Don't use persistent connections, "to be reused connections" also reuse the MySQL session 
			// which results in locked rows not getting released and uncommited transactions would be refused.
			if(!is_null($this->_pdoMetadata))
			{
				try
				{
					$this->_pdoMetadata->query("SELECT 1");
				}
				catch(\PDOException $exc)
				{
					error_log($exc->getMessage()." ".$exc->getTraceAsString());
					
					$this->_pdoMetadata = null;
				}
			}


			if(is_null($this->_pdoMetadata))
			{
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
					$this->_pdoMetadata->exec("SET SESSION wait_timeout = 31536000"); // 365 days.
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
			$processInfo = "Hostname: ".gethostname().". PID: ".getmypid()." MySQL host: ".$this->_mysqlConfig->host." MySQL connection ID:".json_encode($this->_MySQLConnectionIDs[$lockName]);

			try
			{
				if($isReadAcquirer)
				{
					assert(strlen($lockName), "lockName is mandatory when isReadAcquirer is true.");

					$lockHolderInfo = $this->_pdoMetadata()->query("
						SELECT
							lock_acquire_timestamp,
							lock_is_exclusive,
							lock_mysql_connection_id,
							lock_acquirer_pid,
							lock_acquirer_hostname,
							lock_acquirer_app_trace
						FROM `locks_metadata`
						WHERE
							lock_name = ".$this->_pdoMetadata()->quote($lockName)."
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


		/**
		 * Used to kill the process in case the oldest lock hasn't been released for the maximum wait_timeout allowed by MySQL.
		 * If the oldest lock has been acquired more than 48 hours ago, $maxWaitTimeout will be reduced by 1 hour.
		 */
		protected function _checkIfTimeout():void
		{
			if(!count($this->_lockAcquisitionOrder))
			{
				return;
			}

			$processExecutionSeconds = max(0, time() - $_SERVER["REQUEST_TIME"]);

			$maxWaitTimeout = $this->_MySQLMaxWaitTimeout + $this->_lockToAgeSeconds[$this->_lockAcquisitionOrder[0]];

			if(($processExecutionSeconds - $this->_lockToAgeSeconds[$this->_lockAcquisitionOrder[0]]) >= 172800) // 48 hours
			{
				$maxWaitTimeout -= 3600; // 1 hour
			}

			if(($maxWaitTimeout - $processExecutionSeconds) <= 0)
			{
				exit($this->_lockAcquisitionOrder[0]." was being held for longer than maximum wait_timeout.".PHP_EOL);
			}
		}
	}
}
