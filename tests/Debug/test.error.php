<?php

require_once '../../Nette/Debug.php';

/*use Nette::Debug;*/

Debug::enable();



function first($arg1, $arg2)
{
    second(TRUE, FALSE);
}



function second($arg1, $arg2)
{
    third(array(1, 2, 3));
}


function third($arg1)
{
    trigger_error('Error generated by trigger_error', E_USER_ERROR);
}


first(10, 'any string');