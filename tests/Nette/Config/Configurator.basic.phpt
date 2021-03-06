<?php

/**
 * Test: Nette\Config\Configurator and createContainer.
 *
 * @author     David Grudl
 * @package    Nette\Config
 * @subpackage UnitTests
 */

use Nette\Config\Configurator;



require __DIR__ . '/../bootstrap.php';



date_default_timezone_set('America/Los_Angeles');

$configurator = new Configurator;
$configurator->setTempDirectory(TEMP_DIR);
$configurator->addParameters(array(
	'wwwDir' => 'overwritten', // overwrites default value
	'foo2' => '%foo%',         // uses parameter from config file
	'foo3' => '%foo%',         // will be overwritten by config file
));
$container = $configurator->addConfig('files/config.basic.neon', 'production')
	->createContainer();

Assert::same( 'overwritten', $container->parameters['wwwDir'] );
Assert::same( 'hello world', $container->parameters['foo'] );
Assert::same( 'hello world', $container->parameters['foo2'] );
Assert::same( 'overwritten', $container->parameters['foo3'] );
Assert::same( 'hello', $container->parameters['bar'] );
Assert::same( 'hello world', constant('BAR') );
Assert::same( 'Europe/Prague', date_default_timezone_get() );

Assert::same( array(
	'dsn' => 'sqlite2::memory:',
	'user' => 'dbuser',
	'password' => 'secret',
), $container->parameters['database'] );
