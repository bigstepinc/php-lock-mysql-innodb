<?php
declare(strict_types=1);

spl_autoload_register(function(string $className){
	if(substr($className, 0, strlen("Bigstep\\"))=="Bigstep\\")
	{
		//Relying on namespace to folder structure equivalence convention:
		require_once(dirname(__DIR__)."/".str_replace("Bigstep\\", "src/", $className).".php");
	}
});

if(php_sapi_name() !== "cli")
{
	throw new \Exception("Access denied. Only allowed from CLI.");
}

if(count($argv) <= 1)
{
	echo 
		"Usage:".PHP_EOL.
		"  Test_InnoDB_DeadlockAndNonBlocking --databaseName='test' --port=3306 --host='localhost' --username='root' --password='root'".PHP_EOL.PHP_EOL.
		"Details:".PHP_EOL.
		"  --databaseName               The name of the database you want to connect to. Accepts a string. Example: test.".PHP_EOL.
		"  --port                       The port that MySQL uses. Accepts an int. Example: 3306.".PHP_EOL.
		"  --host                       The name of the host you want to connect to. Accepts string. Example: localhost.".PHP_EOL.
		"  --username                   Your MySQL username. Accepts string. Example: root.".PHP_EOL.
		"  --password                   Your MySQL password. Accepts string. Example: root.".PHP_EOL
	;
}
else
{
	$array = [
		"databaseName" => "",
		"port" => 1,
		"host" => "",
		"username" => "",
		"password" => "",
	];

	for($i = 1; $i < $argc; $i++)
	{
		$parameter = explode("=", substr($argv[$i], 2));

		if(count($parameter) === 2)
		{
			if(array_key_exists($parameter[0], $array))
			{
				if(is_numeric($parameter[1]))
				{
					$array[$parameter[0]] = (int)$parameter[1];
				}
				else
				{
					if(is_bool($parameter[1]))
					{
						$array[$parameter[0]] = (bool)$parameter[1];
					}
					else
					{
						$array[$parameter[0]] = $parameter[1];
					}
				}
			}
			else
			{
				throw new Exception("'".$parameter[0]."' is not a valid parameter");
			}
		}
		else
		{
			if(count($parameter) === 1)
			{
				throw new Exception("The format of '".$parameter[0]."' is not valid. It should be --".$parameter[0]."=value");
			}
			else if(count($parameter) > 2)
			{
				throw new Exception("The format of '".$parameter[0]."' is not valid. It should be --".$parameter[0]."=value with no more than one '='");
			}
		}
	}

	$client = new Bigstep\LockInnoDB\Engines\MySQLInnoDB\Driver(null, $array);

	try
	{
		$client->acquire("Non-blocking", true);
		$client->acquire("Non-blocking", true);

		$client->release("Non-blocking");
		$client->release("Non-blocking");

		echo "Failed test. Didn't detect a 'Non-blocking MySQL lock'.".PHP_EOL;
	}
	catch(\Throwable $error)
	{
		if(strpos(strval($error), "Non-blocking MySQL lock") !== false)
		{
			echo "Successfully detected a 'Non-blocking MySQL lock'.".PHP_EOL;
		}
		else
		{
			throw new Exception("'Non-blocking MySQL lock' not detected. Received:".PHP_EOL.$error);
		}
	}

	$client = new Bigstep\LockInnoDB\Engines\MySQLInnoDB\Driver(null, $array);

	try
	{
		$client->acquire("Deadlock", false);
		$client->acquire("Deadlock", false);

		$client->release("Deadlock");
		$client->release("Deadlock");

		echo "Failed test. Didn't detect a 'Deadlock'.".PHP_EOL;
	}
	catch(\Throwable $error)
	{
		if(strpos(strval($error), "Deadlock detected") !== false)
		{
			echo "Successfully detected a 'Deadlock'.".PHP_EOL;
		}
		else
		{
			throw new Exception("'Deadlock' not detected. Received:".PHP_EOL.$error);
		}
	}
}