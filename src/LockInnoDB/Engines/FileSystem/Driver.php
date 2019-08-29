<?php
declare(strict_types=1);

namespace LockInnoDB\Engines\FileSystem
{
	use \LockInnoDB\Exceptions\LockException;


	class Driver extends \LockInnoDB\Engines\DriverBase implements InterfaceDriver
	{
		/**
		 * @var array
		 */
		protected $_fileHandleLocks = [];
		
		
		/**
		 * @var string
		 */
		protected $_locksPath = null;


		/**
		 * @param string $locksPath
		 */
		public function __construct(string $locksPath)
		{
			$this->_locksPath = $locksPath;
		}
		
		
		/**
		 * @return string
		 */
		public function getLocksPath():string
		{
			return $this->_locksPath;
		}


		/**
		 * Mounted Samba shares (Windows shared folders) on Linux do not allow chmod().
		 * This function wraps chmod() to ignore a specific error.
		 *
		 * @param string $fileName
		 * @param int $mode
		 *
		 * @return bool
		 *
		 * @throws \Throwable
		 */
		public function chmod(string $fileName, int $mode):bool
		{
			try
			{
				return chmod($fileName, $mode);
			}
			catch(\Throwable $exc)
			{
				if($exc->getMessage() != "chmod(): Operation not permitted")
				{
					throw $exc;
				}
			}

			return true;
		}


		/**
		 * Lock acquire.
		 * 
		 * @param string $lock
		 * @param bool $isBlocking
		 * @param int $nonBlockingLockRetries = 0
		 * @param int $waitSecondsBeforeRetry = self::NON_BLOCKING_LOCK_WAIT_BEFORE_RETRY_SECONDS_DEFAULT
		 * 
		 * @throws \LockInnoDB\Exceptions\LockException
		 * @throws \Throwable
		 */
		public function acquire(string $lock, bool $isBlocking, int $nonBlockingLockRetries=0, int $waitSecondsBeforeRetry=self::NON_BLOCKING_LOCK_WAIT_BEFORE_RETRY_SECONDS_DEFAULT):void
		{
			$processID = getmypid();
			assert(false !== $processID);
			assert($nonBlockingLockRetries > -1);
			assert(strlen(trim($lock)));
			
			$lockFilePath = $this->_locksPath."/".$lock.".lock";


			$isAllowRetryOnError = true;
			do
			{
				try
				{
					clearstatcache(true, $lockFilePath);
					if(!file_exists($lockFilePath))
					{
						touch($lockFilePath);
						$this->chmod($lockFilePath, 0666);
					}

					if(isset($this->_fileHandleLocks[$lock]) && $this->_fileHandleLocks[$lock])
					{
						throw new \LockInnoDB\Exceptions\LockException("Deadlock detected. Thread tried to acquire the same FileSystem lock (".json_encode($lock).") without releasing first. ".$this->_processInfo(), \LockInnoDB\Exceptions\LockException::DEADLOCK);
					}

					$lockHandle=fopen($lockFilePath, "r+"); // Do not use w+, it will truncate the file even if locked and lose all info.

					break;
				}
				catch(\Throwable $exc)
				{
					if($isAllowRetryOnError)
					{
						$isAllowRetryOnError = false;
						error_log($exc->getMessage()." ".$exc->getTraceAsString());
						continue;
					}

					throw $exc;
				}
			} while(true);
			

			if(!$isBlocking)
			{
				while(!flock($lockHandle, LOCK_EX | LOCK_NB))
				{
					if(--$nonBlockingLockRetries >= 0)
					{
						sleep($waitSecondsBeforeRetry);
					}
					else
						throw new \LockInnoDB\Exceptions\LockException("Non-blocking FileSystem lock ".json_encode($lock).". ".$this->_processInfo(/*isReadAcquirer*/ true, $lock), \LockInnoDB\Exceptions\LockException::NON_BLOCKING_LOCK);
				}
			}
			else
			{
				$isLocked=flock($lockHandle, LOCK_EX);
				assert($isLocked);
			}

			try
			{
				$lockHandleTest=fopen($lockFilePath, "r+"); // Do not use w+ it will truncate the file and all info even if locked.
			}
			catch (\Throwable $exc)
			{
				throw new \LockInnoDB\Exceptions\LockException("Reopening the file after acquiring the FileSystem lock ".json_encode($lock)." failed with: ".$exc->getMessage().". This is probably due to a race condition with the lock file deletion system and failing here and not acquiring the lock is in order, to not cause additional issues. ".$this->_processInfo(/*isReadAcquirer*/ true, $lock), \LockInnoDB\Exceptions\LockException::LOCK_CONFIG_ERROR);
			}
			
			assert(!flock($lockHandleTest, LOCK_EX | LOCK_NB), "Failed to acquire FileSystem lock ".json_encode($lock).". Unknown reason. ".$this->_processInfo());
			fclose($lockHandleTest);

			fseek($lockHandle, 0);
			ftruncate($lockHandle, 0);
			fwrite($lockHandle, json_encode([
				"pid"=>$processID, 
				"acquire_timestamp"=>gmdate(\LockInnoDB\Utils\LockUtils::DATE_ISO8601_ZULU), 
				"lock_acquirer_app_trace"=>\LockInnoDB\Utils\LockUtils::getTraceAsStringWithoutParams(new \Exception())
			]));
			$this->_fileHandleLocks[$lock]=$lockHandle;

			fflush($lockHandle);
		}


		/**
		* Lock release.
		*
		* @param string $lock. Lock name.
		*/	
		public function release(string $lock):void
		{
			if(!array_key_exists($lock, $this->_fileHandleLocks))
			{
				throw new \Exception("::release() FileSystem lock handle not found: ".json_encode($lock).". ".$this->_processInfo());
			}

			flock($this->_fileHandleLocks[$lock], LOCK_UN);
			fclose($this->_fileHandleLocks[$lock]);

			clearstatcache(true, $this->_locksPath."/".$lock.".lock");
			if(file_exists($this->_locksPath."/".$lock.".lock"))
			{
				/*
				*	unlink cannot be used because the error generated if another process it's acquiring lock cannot be catched.
				*	The error (witch is a bash error) will surface through JSONRPC server
				*	because the error pipe will not be empty when the process is closing.
				*/
				//unlink($this->_locksPath."/".$lock.".lock");
				//exec("rm ".$this->_locksPath."/".$lock.".lock");
			}

			$this->_fileHandleLocks[$lock] = null;
			unset($this->_fileHandleLocks[$lock]);
		}


		/**
		 * Releases all locks in $this->_fileHandleLocks.
		 */
		public function releaseAll():void
		{
			foreach(array_keys($this->_fileHandleLocks) as $lock)
			{
				$this->release($lock);
			}
		}
		
		
		/**
		 * Remove unused locks.
		 * This function should be called by cron.
		 */
		public function unusedLocksRemove():void
		{
			foreach(glob($this->_locksPath."/*.lock") as $lockFile)
			{
				if(time() - @filemtime($lockFile) < 86400)
				{
					continue;
				}

				$lockHandle = fopen($lockFile, "r"); // Do not use w+, it will truncate the file even if locked and lose all info.
				
				if(flock($lockHandle, LOCK_EX | LOCK_NB))
				{
					fclose($lockHandle);
					unlink($lockFile);
				}
				
				if(is_resource($lockHandle))
					fclose($lockHandle);
			}
		}


		private function _processInfo(bool $isReadAcquirer = false, string $lock = ""):string
		{
			$processInfo = "Hostname: ".gethostname().". ThisPID: ".getmypid()." Locks directory: ".$this->_locksPath;

			try
			{
				assert(!$isReadAcquirer || strlen($lock), "lock is mandatory when isReadAcquirer is true.");

				clearstatcache(true, $this->_locksPath."/".$lock.".lock");
				if(
					$isReadAcquirer
					&& file_exists($this->_locksPath."/".$lock.".lock") 
					&& filesize($this->_locksPath."/".$lock.".lock")
				)
				{
					$processInfo .= 
						PHP_EOL
						." ***Lock holder***: ".file_get_contents($this->_locksPath."/".$lock.".lock")
					;
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
