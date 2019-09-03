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
		"  Test_InnoDB_MultipleAcquiresAndReleases --databaseName='test' --port=3306 --host='localhost' --username='root' --password='root'".PHP_EOL.PHP_EOL.
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
	$client_1 = new Bigstep\LockInnoDB\Engines\MySQLInnoDB\Driver(null, $array);
	$client_2 = new Bigstep\LockInnoDB\Engines\MySQLInnoDB\Driver(null, $array);

	try
	{
		$client->acquire("January", true);
		$client->acquire("February", true);

		$client_1->acquire("May", true);
		$client_1->acquire("June", true);

		$client->acquire("October", false);

		$client_2->acquire("July", false);
		$client_2->acquire("August", false);

		$client->release("October");
		$client->acquire("March", true);
		$client->release("February");
		$client->acquire("April", true);

		$client_2->acquire("October", false);

		$client_1->acquire("February", false);
		$client_1->release("February");
		$client_1->release("June");

		$client_2->acquire("September", false);
		$client_2->release("August");
		$client_2->acquire("February", false);
		$client_2->acquire("June", false);

		$client->acquire("August", false);

		$client->release("January");
		$client->release("March");
		$client->release("April");
		$client->release("August");

		$client_1->release("May");

		$client_2->release("July");
		$client_2->release("September");
		$client_2->release("October");
		$client_2->release("February");
		$client_2->release("June");

		echo "Successfully ran the test without errors.".PHP_EOL;
	}
	catch(\Throwable $error)
	{
		throw new Exception("No errors should have been thrown. Received:".PHP_EOL.$error);
	}
}