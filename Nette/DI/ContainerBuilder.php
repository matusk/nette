<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004, 2011 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Nette\DI;

use Nette,
	Nette\Utils\Validators,
	Nette\Utils\PhpGenerator\Helpers as PhpHelpers,
	Nette\Utils\PhpGenerator\PhpLiteral;



/**
 * Basic container builder.
 *
 * @author     David Grudl
 * @property-read array $definitions
 * @property-read array $dependencies
 */
class ContainerBuilder extends Nette\Object
{
	const CREATED_SERVICE = 'self',
		THIS_CONTAINER = 'container';

	/** @var array  %param% will be expanded */
	public $parameters = array();

	/** @var array of ServiceDefinition */
	private $definitions = array();

	/** @var array for auto-wiring */
	private $classes;

	/** @var array of file names */
	private $dependencies = array();



	/**
	 * Adds new service definition. The expressions %param% and @service will be expanded.
	 * @param  string
	 * @return ServiceDefinition
	 */
	public function addDefinition($name)
	{
		if (isset($this->definitions[$name])) {
			throw new Nette\InvalidStateException("Service '$name' has already been added.");
		}
		return $this->definitions[$name] = new ServiceDefinition;
	}



	/**
	 * Removes the specified service definition.
	 * @param  string
	 * @return void
	 */
	public function removeDefinition($name)
	{
		unset($this->definitions[$name]);
	}



	/**
	 * Gets the service definition.
	 * @param  string
	 * @return ServiceDefinition
	 */
	public function getDefinition($name)
	{
		if (!isset($this->definitions[$name])) {
			throw new MissingServiceException("Service '$name' not found.");
		}
		return $this->definitions[$name];
	}



	/**
	 * Gets all service definitions.
	 * @return array
	 */
	public function getDefinitions()
	{
		return $this->definitions;
	}



	/**
	 * Does the service definition exist?
	 * @param  string
	 * @return bool
	 */
	public function hasDefinition($name)
	{
		return isset($this->definitions[$name]);
	}



	/********************* class resolving ****************d*g**/



	/**
	 * Resolves service name by type.
	 * @param  string  class or interface
	 * @return string  service name or NULL
	 * @throws ServiceCreationException
	 */
	public function getByClass($class)
	{
		$lower = ltrim(strtolower($class), '\\');
		if (!isset($this->classes[$lower])) {
			return;

		} elseif (count($this->classes[$lower]) === 1) {
			return $this->classes[$lower][0];

		} else {
			throw new ServiceCreationException("Multiple services of type $class found: " . implode(', ', $this->classes[$lower]));
		}
	}



	/**
	 * Gets the service objects of the specified tag.
	 * @param  string
	 * @return array of [service name => tag attributes]
	 */
	public function findByTag($tag)
	{
		$found = array();
		foreach ($this->definitions as $name => $def) {
			if (isset($def->tags[$tag])) {
				$found[$name] = $def->tags[$tag];
			}
		}
		return $found;
	}



	/**
	 * Creates a list of arguments using autowiring.
	 * @return array
	 */
	public function autowireArguments($class, $method, array $arguments)
	{
		$rc = Nette\Reflection\ClassType::from($class);
		if (!$rc->hasMethod($method)) {
			if (!Nette\Utils\Validators::isList($arguments)) {
				throw new ServiceCreationException("Unable to pass specified arguments to $class::$method().");
			}
			return $arguments;
		}

		$rm = $rc->getMethod($method);
		if ($rm->isAbstract() || !$rm->isPublic()) {
			throw new ServiceCreationException("$rm is not callable.");
		}
		$this->addDependency($rm->getFileName());
		return Helpers::autowireArguments($rm, $arguments, $this);
	}



	/**
	 * Generates $dependencies, $classes and expands and normalize class names.
	 * @return array
	 */
	public function prepareClassList()
	{
        // complete class-factory pairs; expand classes
		foreach ($this->definitions as $name => $def) {
			if ($def->class) {
				$def->class = $def->class === self::CREATED_SERVICE ? $name : $this->expand($def->class);
				if (!$def->factory) {
					$def->factory = new Statement($def->class);
				}
			} elseif (!$def->factory) {
				throw new ServiceCreationException("Class and factory are missing in service '$name' definition.");
			}
			if ($def->factory && $def->factory->entity === self::CREATED_SERVICE) {
				$def->factory->entity = $name;
			}
		}

		// complete classes
		$this->classes = FALSE;
		foreach ($this->definitions as $name => $def) {
			$this->resolveClass($name);
		}

		//  build auto-wiring list
		$this->classes = array();
		foreach ($this->definitions as $name => $def) {
			if (!$def->class) {
				continue;
			}
			if (!class_exists($def->class) && !interface_exists($def->class)) {
				throw new Nette\InvalidStateException("Class $def->class has not been found.");
			}
			$def->class = Nette\Reflection\ClassType::from($def->class)->getName();
			if ($def->autowired) {
				foreach (class_parents($def->class) + class_implements($def->class) + array($def->class) as $parent) {
					$this->classes[strtolower($parent)][] = $name;
				}
			}
		}

		$this->dependencies = array();
		foreach ($this->classes as $class => $foo) {
			$this->addDependency(Nette\Reflection\ClassType::from($class)->getFileName());
		}
	}



	private function resolveClass($name, $recursive = array())
	{
		if (isset($recursive[$name])) {
			throw new Nette\InvalidArgumentException('Circular reference detected for services: ' . implode(', ', array_keys($recursive)) . '.');
		}
		$recursive[$name] = TRUE;

		$def = $this->definitions[$name];
		$factory = $this->normalizeEntity($this->expand($def->factory->entity));

		if ($def->class) {
			return $def->class;

		} elseif (is_array($factory)) { // method calling
			if ($service = $this->getServiceName($factory[0])) {
				if (strpos($service, '\\') !== FALSE) { // @\Class
					throw new ServiceCreationException("Unable resolve class name for service '$name'.");
				}
				$factory[0] = $this->resolveClass($service, $recursive);
				if (!$factory[0]) {
					return;
				}
			}
			$factory = callback($factory);
			if (!$factory->isCallable()) {
				throw new Nette\InvalidStateException("Factory '$factory' is not callable.");
			}
			try {
				$reflection = $factory->toReflection();
				$def->class = preg_replace('#[|\s].*#', '', $reflection->getAnnotation('return'));
				if ($def->class && !class_exists($def->class) && $def->class[0] !== '\\' && $reflection instanceof \ReflectionMethod) {
					/**/$def->class = $reflection->getDeclaringClass()->getNamespaceName() . '\\' . $def->class;/**/
				}
			} catch (\ReflectionException $e) {
			}

		} elseif ($service = $this->getServiceName($factory)) { // alias or factory
			if (strpos($service, '\\') !== FALSE) { // @\Class
				/*5.2* $service = ltrim($service, '\\');*/
				$def->autowired = FALSE;
				return $def->class = $service;
			}
			if ($this->definitions[$service]->shared) {
				$def->autowired = FALSE;
			}
			return $def->class = $this->resolveClass($service, $recursive);

		} else {
			return $def->class = $factory; // class name
		}
	}



	/**
	 * Adds a file to the list of dependencies.
	 * @return void
	 */
	public function addDependency($file)
	{
		$this->dependencies[$file] = TRUE;
	}



	/**
	 * Returns the list of dependent files.
	 * @return array
	 */
	public function getDependencies()
	{
		unset($this->dependencies[FALSE]);
		return array_keys($this->dependencies);
	}



	/********************* code generator ****************d*g**/



	/**
	 * Generates PHP class.
	 * @return Nette\Utils\PhpGenerator\ClassType
	 */
	public function generateClass($parentClass = 'Nette\DI\Container')
	{
		unset($this->definitions[self::THIS_CONTAINER]);
		$this->addDefinition(self::THIS_CONTAINER)->setClass($parentClass);

		$this->prepareClassList();

		$class = new Nette\Utils\PhpGenerator\ClassType('Container');
		$class->addExtend($parentClass);
		$class->addMethod('__construct')
			->addBody('parent::__construct(?);', array($this->expand($this->parameters)));

		$classes = $class->addProperty('classes', array());
		foreach ($this->classes as $name => $foo) {
			try {
				$classes->value[$name] = $this->sanitizeName($this->getByClass($name));
			} catch (ServiceCreationException $e) {
				$classes->value[$name] = new PhpLiteral('FALSE, //' . strstr($e->getMessage(), ':'));
			}
		}

		$meta = $class->addProperty('meta', array());
		foreach ($this->definitions as $name => $def) {
			foreach ($this->expand($def->tags) as $tag => $value) {
				$meta->value[$name][Container::TAGS][$tag] = $value;
			}
		}

		foreach ($this->definitions as $name => $def) {
			try {
				$type = $def->class ?: 'object';
				$sanitized = $this->sanitizeName($name);
				if (!PhpHelpers::isIdentifier($sanitized)) {
					throw new ServiceCreationException('Name contains invalid characters.');
				}
				if ($def->shared && $name === $sanitized) {
					$class->addDocument("@property $type \$$name");
				}
				$method = $class->addMethod(($def->shared ? 'createService' : 'create') . ucfirst($sanitized))
					->addDocument("@return $type")
					->setVisibility($def->shared || $def->internal ? 'protected' : 'public')
					->setBody($name === self::THIS_CONTAINER ? 'return $this;' : $this->generateService($name));

				foreach ($this->expand($def->parameters) as $k => $v) {
					$tmp = explode(' ', is_int($k) ? $v : $k);
					$param = is_int($k) ? $method->addParameter(end($tmp)) : $method->addParameter(end($tmp), $v);
					if (isset($tmp[1])) {
						$param->setTypeHint($tmp[0]);
					}
				}
			} catch (\Exception $e) {
				throw new ServiceCreationException("Service '$name': " . $e->getMessage()/**/, NULL, $e/**/);
			}
		}

		return $class;
	}



	/**
	 * Generates body of service method.
	 * @return string
	 */
	private function generateService($name)
	{
		$def = $this->definitions[$name];
		$parameters = $this->parameters;
		foreach ($this->expand($def->parameters) as $k => $v) {
			$v = explode(' ', is_int($k) ? $v : $k);
			$parameters[end($v)] = new PhpLiteral('$' . end($v));
		}

		$code = '$service = ' . $this->formatStatement(Helpers::expand($def->factory, $parameters, TRUE)) . ";\n";

		if ($def->class && $def->class !== $def->factory->entity) {
			$code .= PhpHelpers::formatArgs("if (!\$service instanceof $def->class) {\n"
				. "\tthrow new Nette\\UnexpectedValueException(?);\n}\n",
				array("Unable to create service '$name', value returned by factory is not $def->class type.")
			);
		}

		foreach ((array) $def->setup as $setup) {
			$setup = Helpers::expand($setup, $parameters, TRUE);
			if (is_string($setup->entity) && strpbrk($setup->entity, ':@') === FALSE) { // auto-prepend @self
				$setup->entity = array("@$name", $setup->entity);
			}
			$code .= $this->formatStatement($setup, $name) . ";\n";
		}

		return $code .= 'return $service;';
	}



	/**
	 * Formats PHP code for class instantiating, function calling or property setting in PHP.
	 * @return string
	 * @internal
	 */
	public function formatStatement(Statement $statement, $self = NULL)
	{
		$entity = $this->normalizeEntity($statement->entity);
		$arguments = (array) $statement->arguments;

		if ($entity instanceof PhpLiteral) {
			return $this->formatPhp('call_user_func_array(?, ?)', array($entity, $arguments));

		} elseif ($service = $this->getServiceName($entity)) { // factory calling or service retrieving
			if ($this->definitions[$service]->shared) {
				if ($arguments) {
				throw new ServiceCreationException("Unable to call service '$entity'.");
			}
				return $this->formatPhp('$this->?', array($this->sanitizeName($service)));
			}
			$params = array();
			foreach ($this->definitions[$service]->parameters as $k => $v) {
				$params[] = preg_replace('#\w+$#', '\$$0', (is_int($k) ? $v : $k)) . (is_int($k) ? '' : ' = ' . PhpHelpers::dump($v));
			}
			$rm = new \ReflectionFunction(create_function(implode(', ', $params), ''));
			$arguments = Helpers::autowireArguments($rm, $arguments, $this);
			return $this->formatPhp('$this->?(?*)', array('create' . ucfirst($service), $arguments), $self);

		} elseif ($entity === 'not') { // operator
			return $this->formatPhp('!?', array($arguments[0]));

		} elseif (is_string($entity)) { // class name
		    if ($constructor = Nette\Reflection\ClassType::from($entity)->getConstructor()) {
				$this->addDependency($constructor->getFileName());
				$arguments = Helpers::autowireArguments($constructor, $arguments, $this);
			} elseif ($arguments) {
				throw new ServiceCreationException("Unable to pass arguments, class $entity has no constructor.");
			}
			return $this->formatPhp("new $entity" . ($arguments ? '(?*)' : ''), array($arguments));

		} elseif (!Validators::isList($entity) || count($entity) !== 2) {
			throw new Nette\InvalidStateException("Expected class, method or property, " . implode('::', $entity) . " given.");

		} elseif ($entity[0] === '') { // globalFunc
			return $this->formatPhp("$entity[1](?*)", array($arguments), $self);

		} elseif (strpos($entity[1], '$') !== FALSE) { // property setter
			if ($this->getServiceName($entity[0], $self)) {
				return $this->formatPhp('?->? = ?', array($entity[0], substr($entity[1], 1), $statement->arguments), $self);
			} else {
				return $this->formatPhp($entity[0] . '::$? = ?', array(substr($entity[1], 1), $statement->arguments), $self);
			}

		} elseif ($service = $this->getServiceName($entity[0], $self)) { // service method
			if ($this->definitions[$service]->class) {
				$arguments = $this->autowireArguments($this->definitions[$service]->class, $entity[1], $arguments);
			}
			return $this->formatPhp('?->?(?*)', array($entity[0], $entity[1], $arguments), $self);

		} else { // static method
			$arguments = $this->autowireArguments($entity[0], $entity[1], $arguments);
			return $this->formatPhp("$entity[0]::$entity[1](?*)", array($arguments), $self);
		}
	}



	/**
	 * Formats PHP statement.
	 * @return string
	 */
	private function formatPhp($statement, $args, $self = NULL)
	{
		$that = $this;
		array_walk_recursive($args, function(&$val) use ($self, $that) {
			list($val) = $that->normalizeEntity(array($val));

			if ($val instanceof Statement) {
				$val = new PhpLiteral($that->formatStatement($val, $self));

			} elseif ($val === '@' . ContainerBuilder::THIS_CONTAINER) {
				$val = new PhpLiteral('$this');

			} elseif ($service = $that->getServiceName($val, $self)) {
				$val = $service === $self ? '$service' : $that->formatStatement(new Statement($val));
				$val = new PhpLiteral($val, $self);
				}
		});
		return PhpHelpers::formatArgs($statement, $args);
	}



	/**
	 * Expands %placeholders% in strings (recursive).
	 * @param  mixed
	 * @return mixed
	 */
	public function expand($value)
	{
		return Helpers::expand($value, $this->parameters, TRUE);
	}



	private static function sanitizeName($name)
	{
		return strtr($name, '\\', '__');
	}



	/** @internal */
	public function normalizeEntity($entity)
	{
		$entity = is_string($entity) && strpos($entity, '::') !== FALSE // Class::method -> [Class, method]
			? explode('::', $entity)
			: $entity;

		if (is_array($entity) && $entity[0] instanceof ServiceDefinition) { // [ServiceDefinition, ...] -> [@serviceName, ...]
			$tmp = array_keys($this->definitions, $entity[0], TRUE);
			$entity[0] = "@$tmp[0]";

		} elseif ($entity instanceof ServiceDefinition) { // ServiceDefinition -> @serviceName
			$tmp = array_keys($this->definitions, $entity, TRUE);
			$entity = "@$tmp[0]";

		} elseif (is_array($entity) && $entity[0] === $this) { // [$this, ...] -> [@container, ...]
			$entity[0] = '@' . ContainerBuilder::THIS_CONTAINER;
		}
		return $entity; // Class, @service, [Class, member], [@service, member], [, globalFunc]
	}



	/**
	 * Converts @service or @\Class -> service name and checks its existence.
	 * @param  mixed
	 * @return string  of FALSE, if argument is not service name
	 */
	public function getServiceName($arg, $self = NULL)
	{
		if (!is_string($arg) || !preg_match('#^@[\w\\\\]+$#', $arg)) {
			return FALSE;
		}
		$service = substr($arg, 1);
		if ($service === self::CREATED_SERVICE) {
			$service = $self;
		}
		if (strpos($service, '\\') !== FALSE) {
			if ($this->classes === FALSE) { // may be disabled by prepareClassList
				return $service;
			}
			$res = $this->getByClass($service);
			if (!$res) {
				throw new ServiceCreationException("Reference to missing service of type $service.");
			}
			return $res;
		}
		if (!isset($this->definitions[$service])) {
			throw new ServiceCreationException("Reference to missing service '$service'.");
		}
		return $service;
	}

}
