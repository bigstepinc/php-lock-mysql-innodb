<?php
declare(strict_types=1);

namespace LockInnoDB\Utils
{
	class LockUtils
	{
		protected function __construct()
		{
		}


		/**
		 * Removes param values from the result of \Throwable::getTraceAsString() and returns the cleaned trace.
		 * 
		 * @return string
		 */
		public static function getTraceAsStringWithoutParams(\Throwable $exc):string
		{
			return preg_replace("/\\([^:]+\\)\$/m", "", $exc->getTraceAsString());
		}


		/**
		 * Date and time format in accordance to ISO8601 standard.
		 */
		const DATE_ISO8601_ZULU="Y-m-d\\TH:i:s\\Z";
	}
}

