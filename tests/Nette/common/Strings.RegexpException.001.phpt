<?php

/**
 * Test: Nette\Strings and RegexpException run-time error.
 *
 * @author     David Grudl
 * @package    Nette
 * @subpackage UnitTests
 */

use Nette\Strings;



require __DIR__ . '/../bootstrap.php';



ini_set('pcre.backtrack_limit', 3); // forces PREG_BACKTRACK_LIMIT_ERROR

Assert::throws(function() {
	Strings::split('0123456789', '#.*\d#');
}, 'Nette\RegexpException', 'Backtrack limit was exhausted (pattern: #.*\d#)');

Assert::throws(function() {
	Strings::match('0123456789', '#.*\d#');
}, 'Nette\RegexpException', 'Backtrack limit was exhausted (pattern: #.*\d#)');

Assert::throws(function() {
	Strings::matchAll('0123456789', '#.*\d#');
}, 'Nette\RegexpException', 'Backtrack limit was exhausted (pattern: #.*\d#)');

Assert::throws(function() {
	Strings::replace('0123456789', '#.*\d#', 'x');
}, 'Nette\RegexpException', 'Backtrack limit was exhausted (pattern: #.*\d#)');

function cb() { return 'x'; }

Assert::throws(function() {
	Strings::replace('0123456789', '#.*\d#', new Nette\Callback('cb'));
}, 'Nette\RegexpException', 'Backtrack limit was exhausted (pattern: #.*\d#)');