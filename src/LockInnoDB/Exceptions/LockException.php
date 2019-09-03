<?php
declare(strict_types=1);

namespace Bigstep\LockInnoDB\Exceptions
{
	class LockException extends \Exception
	{
		/**
		* A lock could not be acquired because it is already locked or some error has occurred.
		*/
		const COULD_NOT_ACQUIRE_LOCK=1;
		
		
		/**
		* Non-blocking lock.
		* Non-blocking locks will throw this exception code.
		*/
		const NON_BLOCKING_LOCK=2;


		/*
		* Lock inegrity failed. This is triggered on missing lock data after lock initialization.
		*/
		const LOCK_INTEGRITY_FAILED=3;


		/*
		* Lock config error.
		*/	
		const LOCK_CONFIG_ERROR = 4;


		/**
		 * Classic deadlock scenario.
		 */
		const DEADLOCK = 5;
	}
}