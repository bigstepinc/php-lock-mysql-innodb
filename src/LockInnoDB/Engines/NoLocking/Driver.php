<?php
declare(strict_types=1);

namespace LockInnoDB\Engines\NoLocking
{
	use \LockInnoDB\Exceptions\LockException;
	use \LockInnoDB\Engines\DriverBase;


	class Driver extends DriverBase
	{
		public function __construct()
		{
		}


		/**
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
		}


		/**
		 * @param string $lockName
		 * 
		 * @throws \LockInnoDB\Exceptions\LockException
		 */
		public function release(string $lockName):void
		{
		}


		public function unusedLocksRemove():void
		{
		}


		public function disconnect():void
		{
		}

		public function releaseAll():void
		{
		}
	}
}
