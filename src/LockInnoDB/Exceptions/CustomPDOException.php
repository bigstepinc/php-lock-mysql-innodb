<?php
declare(strict_types=1);

namespace LockInnoDB\Exceptions
{
	class CustomPDOException extends \PDOException
	{
		public function __construct(string $message="", int $code = 0, \Throwable $previousException = null)
		{
			$this->message = $message;
			$this->code = $code;

			if(is_a($previousException, "PDOException"))
			{
				$this->errorInfo = $previousException->errorInfo;
			}
		}

		/**
		 * PDO general error.
		 */
		const GENERAL_ERROR = 1;

		/**
		 * All available connections are used.
		 * max_connections can be increased to allow more connections.
		 */
		const TOO_MANY_CONNECTIONS = 2;

		/**
		 * Query not allowed in quoting mode error.
		 */
		const QUERY_NOT_ALLOWED_IN_QUOTING_MODE = 3;

		/**
		 * PDO config error.
		 */
		const CONFIG_ERROR = 4;

		/**
		 * The required transaction coordinator error.
		 */
		const TRANSACTION_COORDINATOR_REQUIRED = 5;

		/**
		 * Transaction initialization is locked.
		 */
		const TRANSACTION_INIT_LOCKED = 6;
	}
}