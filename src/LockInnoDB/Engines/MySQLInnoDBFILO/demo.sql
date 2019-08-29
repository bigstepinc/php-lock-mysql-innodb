SET sql_mode = 'STRICT_ALL_TABLES';


ROLLBACK;
CREATE TABLE IF NOT EXISTS `locks`(
  `lock_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`lock_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Locked files.';


-- Only InnoDB supports ROLLBACK TO SAVEPOINT and releasing previously locked rows (although docs say otherwise).
-- If this returns more than 0 rows, the application should throw.
SHOW TABLE STATUS WHERE `Name`='locks' AND `Engine` <> 'InnoDB';


-- The correct transaction isolation level for this to work.
SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;
BEGIN;


-- Non-blocking lock.
-- Application needs to handle MySQL SQLSTATE ER_LOCK_WAIT_TIMEOUT (HY000) as lock acquire failure (because it is acquired somewhere else).
SET innodb_lock_wait_timeout = 1;

-- Blocking lock.
SET innodb_lock_wait_timeout = 1073741824 /*maximum permitted value*/;


-- Test if MySQL InnoDB is working correctly:
-- Uncomment in 2nd connection with transaction, to check if ccc is still *incorrectly* blocking.
-- SAVEPOINT test_if_lock;
-- REPLACE INTO `locks` SET lock_name='ccc';
-- REPLACE INTO `locks` SET lock_name='ddd';

-- The above shouldn't have blocked.
-- ROLLBACK TO SAVEPOINT test_if_lock;


SAVEPOINT aaa;
REPLACE INTO `locks` SET lock_name='aaa';

SAVEPOINT bbb;
REPLACE INTO `locks` SET lock_name='bbb';

SAVEPOINT ccc;
REPLACE INTO `locks` SET lock_name='ccc';

SAVEPOINT ddd;
REPLACE INTO `locks` SET lock_name='ddd';


-- Releases the ccc **and ddd** locks.
ROLLBACK TO SAVEPOINT ccc;


SELECT SLEEP(30);
-- The aaa abd bbb rows are now blocking any other "REPLACE INTO" lock queries **in other** similar transactions.
-- The ccc and ddd are not blocking anyone.


-- Never commit.
-- Release all locks:
ROLLBACK;
