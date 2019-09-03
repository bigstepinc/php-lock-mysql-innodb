<?php
declare(strict_types=1);

namespace LockInnoDB\Engines
{
	abstract class DriverBase
	{
		/**
		 * Lock acquire.
		 *
		 * Retries will happen only for non-blocking lock.
		 *
		 * @param string $key
		 * @param boolean $isBlocking . Lock type.
		 * @param int $nonBlockingLockRetries
		 * @param int $waitSecondsBeforeRetry
		 */
		abstract function acquire(string $key, bool $isBlocking, int $nonBlockingLockRetries=0, int $waitSecondsBeforeRetry=self::NON_BLOCKING_LOCK_WAIT_BEFORE_RETRY_SECONDS_DEFAULT):void;


		/**
		 * Lock release.
		 *
		 * @param string $key
		 */
		abstract function release(string $key):void;


		/**
		 * Releases all acquired locks.
		 * To be used when recycling a process, maybe.
		 * 
		 * Think hard before calling this function!
		 */
		abstract function releaseAll():void;
		
		
		/**
		* Remove unused lock files.
		*/
		abstract function unusedLocksRemove():void;


		/**
		 * @param string $lockName
		 */
		public function assertConnected(string $lockName):void
		{
		}


		public function disconnect():void
		{
		}


		/**
		 * @return string
		 */
		public function getLocksPath():string
		{
			return sys_get_temp_dir();
		}


		const LOCK_MYSQL = "MySQL";

		/**
		* No. of seconds to wait before retry a non-blocking lock.
		*/
		const NON_BLOCKING_LOCK_WAIT_BEFORE_RETRY_SECONDS_DEFAULT=2;
	}
}