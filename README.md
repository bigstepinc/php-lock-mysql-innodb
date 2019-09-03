# Centralized locking with InnoDB
Centralised blocking or non-blocking locks using MySQL's InnoDB transaction features.


Requirements
============

* PHP >= 7.2.0


Installation
============

If you are using composer, run the following command in the root directory of your project.

    composer require bigstep/lock-mysql-innodb


Usage
=====

You will need a config with the following MySQL parameters:

    $array = [
        "databaseName" => "database",
        "port" => 3306,
        "host" => "localhost",
        "username" => "username",
        "password" => "password"
    ];

Then you can instantiate the MySQL InnoDB Driver and start acquiring locks.

    $client = new LockInnoDB\Engines\MySQLInnoDB\Driver(/*\LockInnoDB\Engines\DriverBase*/ null, $array);

    $client->acquire("Lock1", true);
    $client->release("Lock1");