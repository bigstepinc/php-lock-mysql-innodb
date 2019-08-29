SET sql_mode = 'STRICT_ALL_TABLES';


ROLLBACK;


CREATE TABLE `locks` (
	`lock_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	PRIMARY KEY (`lock_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `locks_metadata` (
	`lock_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	`lock_acquire_timestamp` char(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	`lock_is_exclusive` tinyint(1) unsigned NOT NULL,
	`lock_mysql_connection_id` bigint(20) unsigned NOT NULL,
	`lock_acquirer_pid` bigint(20) unsigned NOT NULL,
	`lock_acquirer_hostname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	`lock_acquirer_app_trace` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	PRIMARY KEY (`lock_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- The correct transaction isolation level for this to work.
SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;
BEGIN;


-- Non-blocking lock.
-- Application needs to handle MySQL SQLSTATE ER_LOCK_WAIT_TIMEOUT (HY000) as lock acquire failure (because it is acquired somewhere else).
SET innodb_lock_wait_timeout = 1;

-- Blocking lock.
SET innodb_lock_wait_timeout = 2147483 /*maximum permitted value for windows*/;
-- SET innodb_lock_wait_timeout = 1073741824 /*maximum permitted value for linux*/;


REPLACE INTO `locks` SET lock_name='aaa';


SELECT SLEEP(30);
-- The aaa row is now blocking any other "REPLACE INTO" lock queries **in other** similar transactions.


-- Never commit.
-- Release all locks:
ROLLBACK;