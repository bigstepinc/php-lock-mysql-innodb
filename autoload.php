<?php
declare(strict_types=1);

spl_autoload_register(function(string $className){
	if(substr($className, 0, strlen("Bigstep\\"))=="Bigstep\\")
	{
		//Relying on namespace to folder structure equivalence convention:
		require_once(getcwd()."/".str_replace("Bigstep\\", "src/", $className).".php");
	}
});
