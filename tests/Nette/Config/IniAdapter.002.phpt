<?php

/**
 * Test: Nette\Config\Adapters\IniAdapter errors.
 *
 * @author     David Grudl
 * @package    Nette\Config
 * @subpackage UnitTests
 */

use Nette\Config;



require __DIR__ . '/../bootstrap.php';



Assert::throws(function() {
	$config = new Config\Loader;
	$config->load('files/config.scalar1.ini');
}, 'Nette\InvalidStateException', "Invalid section [scalar.set] in file '%a%'.");


Assert::throws(function() {
	$config = new Config\Loader;
	$config->load('files/config.scalar2.ini');
}, 'Nette\InvalidStateException', "Invalid key 'date.timezone' in section [set] in file '%a%'.");


Assert::throws(function() {
	$config = new Config\Loader;
	$config->load('files/config.malformed.ini');
}, 'Nette\InvalidStateException', "parse_ini_file(): syntax error, unexpected \$end, expecting ']' in %a% on line 1");
