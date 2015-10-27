<?php
/**
 * @package     Joomla.Libraries
 * @subpackage  Plugin
 *
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\CMS\Plugin;

defined('JPATH_PLATFORM') or die;

use Joomla\Event\DispatcherInterface;
use Joomla\Event\DispatcherAwareInterface;
use Joomla\Event\DispatcherAwareTrait;
use Joomla\Event\AbstractEvent;
use Joomla\Registry\Registry;
use JFactory;
use ReflectionObject;
use ReflectionMethod;
use ReflectionParameter;

/**
 * JPlugin Class
 *
 * @since  1.5
 */
abstract class Plugin implements DispatcherAwareInterface
{
	use DispatcherAwareTrait;

	/**
	 * A Registry object holding the parameters for the plugin
	 *
	 * @var    Registry
	 * @since  1.5
	 */
	public $params = null;

	/**
	 * The name of the plugin
	 *
	 * @var    string
	 * @since  1.5
	 */
	protected $_name = null;

	/**
	 * The plugin type
	 *
	 * @var    string
	 * @since  1.5
	 */
	protected $_type = null;

	/**
	 * The application we are running in
	 *
	 * TODO REFACTOR ME! Use a Container, not the application itself
	 *
	 * @var    \JApplicationBase
	 * @since  2.5
	 */
	protected $app = null;

	/**
	 * The database driver we are talking to
	 *
	 * TODO REFACTOR ME! No need for it once we have a Container
	 *
	 * @var    \JDatabaseDriver
	 * @since  2.5
	 */
	protected $db = null;

	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  3.1
	 */
	protected $autoloadLanguage = false;

	/**
	 * Should I try to detect and register legacy event listeners, i.e. methods which accept unwrapped arguments? While
	 * this maintains a great degree of backwards compatibility to Joomla! 3.x-style plugins it is much slower. You are
	 * advised to implement your plugins using proper Listeners, methods accepting an AbstractEvent as their sole
	 * parameter, for best performance. Also bear in mind that Joomla! 5.x onwards will only allow proper listeners,
	 * removing support for legacy Listeners.
	 *
	 * @var    boolean
	 * @since  4.0
	 *
	 * @deprecated
	 */
	protected $allowLegacyListeners = true;

	/**
	 * Constructor
	 *
	 * @param   DispatcherInterface  &$subject  The object to observe
	 * @param   array                $config    An optional associative array of configuration settings.
	 *                                          Recognized key values include 'name', 'group', 'params', 'language'
	 *                                          (this list is not meant to be comprehensive).
	 *
	 * @since   1.5
	 */
	public function __construct(&$subject, $config = array())
	{
		// Get the parameters.
		if (isset($config['params']))
		{
			if ($config['params'] instanceof Registry)
			{
				$this->params = $config['params'];
			}
			else
			{
				$this->params = new Registry;
				$this->params->loadString($config['params']);
			}
		}

		// Get the plugin name.
		if (isset($config['name']))
		{
			$this->_name = $config['name'];
		}

		// Get the plugin type.
		if (isset($config['type']))
		{
			$this->_type = $config['type'];
		}

		// Load the language files if needed.
		if ($this->autoloadLanguage)
		{
			$this->loadLanguage();
		}

		// Ensure there is an application object attached
		if (is_null($this->app))
		{
			$this->app = JFactory::getApplication();
		}

		// Ensure there is a database object attached
		if (is_null($this->db))
		{
			$this->db = JFactory::getDbo();
		}

		// Set the dispatcher we are to register our listeners with
		$this->setDispatcher($subject);

		// Register the event listeners with the dispatcher. Override the registerListeners method to customise.
		$this->registerListeners();
	}

	/**
	 * Loads the plugin language file
	 *
	 * @param   string  $extension  The extension for which a language file should be loaded
	 * @param   string  $basePath   The basepath to use
	 *
	 * @return  boolean  True, if the file has successfully loaded.
	 *
	 * @since   1.5
	 */
	public function loadLanguage($extension = '', $basePath = JPATH_ADMINISTRATOR)
	{
		if (empty($extension))
		{
			$extension = 'Plg_' . $this->_type . '_' . $this->_name;
		}

		$lang = JFactory::getLanguage();

		return $lang->load(strtolower($extension), $basePath, null, false, true)
			|| $lang->load(strtolower($extension), JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name, null, false, true);
	}

	/**
	 * Registers legacy Listeners to the Dispatcher, emulating how plugins worked under Joomla! 3.x and below.
	 *
	 * By default, this method will look for all public methods whose name starts with "on". It will register
	 * lambda functions (closures) which try to unwrap the arguments of the dispatched Event into method call
	 * arguments and call your on<Something> method. The result will be passed back to the Event into its 'result'
	 * argument.
	 *
	 * @return  void
	 *
	 * @since   4.0
	 */
	protected function registerListeners()
	{
		$reflectedObject = new ReflectionObject($this);
		$methods = $reflectedObject->getMethods(ReflectionMethod::IS_PUBLIC);

		/** @var ReflectionMethod $method */
		foreach ($methods as $method)
		{
			if (substr($method->name, 0, 2) != 'on')
			{
				continue;
			}

			// Save time if I'm not to detect legacy listeners
			if (!$this->allowLegacyListeners)
			{
				$this->registerListener($method->name);

				continue;
			}

			/** @var ReflectionParameter[] $parameters */
			$parameters = $method->getParameters();

			// If the parameter count is not 1 it is by definition a legacy listener
			if (count($parameters) != 1)
			{
				$this->registerLegacyListener($method->name);

				continue;
			}

			/** @var ReflectionParameter $param */
			$param = array_shift($parameters);
			$typeHint = $param->getClass();
			$paramName = $param->getName();

			// No type hint / type hint class not an event and parameter name is not "event"? It's a legacy listener.
			if ((empty($typeHint) || !$typeHint->implementsInterface('Joomla\\Event\\EventInterface')) && ($paramName != 'event'))
			{
				$this->registerLegacyListener($method->name);

				continue;
			}

			// Everything checks out, this is a proper listener.
			$this->registerListener($method->name);
		}
	}

	/**
	 * Registers a legacy event listener, i.e. a method which accepts individual arguments instead of an AbstractEvent
	 * in its arguments. This provides backwards compatibility to Joomla! 3.x-style plugins.
	 *
	 * This method will register lambda functions (closures) which try to unwrap the arguments of the dispatched Event
	 * into old style method arguments and call your on<Something> method with them. The result will be passed back to
	 * the Event, as an element into an array argument called 'result'.
	 *
	 * @param   string  $methodName  The method name to register
	 *
	 * @return  void
	 *
	 * @since   4.0
	 */
	protected final function registerLegacyListener($methodName)
	{
		$this->getDispatcher()->addListener(
			$methodName, function(AbstractEvent $event) use ($methodName) {
				// Get the event arguments
				$arguments = $event->getArguments();

				// Map the associative argument array to a numeric indexed array for efficiency (see the switch statement below).
				$arguments = array_values($arguments);

				// Extract any old results; they must not be part of the method call.
				$allResults = [];

				if (isset($arguments['result']))
				{
					$allResults = $arguments['result'];

					unset($arguments['result']);
				}

				/**
				 * Calling the method directly is faster than using call_user_func_array, hence this argument
				 * unpacking switch statement. Please do not wrap it back to a single line, it will hurt performance.
				 *
				 * If we raise minimum requirements to PHP 5.6 we can use array unpacking and remove the switch for
				 * even better results, i.e. replace the switch with:
				 * $result = $this->{$methodName}(...$arguments);
				 */
				switch (count($arguments))
				{
					case 0:
						$result = $this->{$methodName}();
						break;
					case 1:
						$result = $this->{$methodName}($arguments[0]);
						break;
					case 2:
						$result = $this->{$methodName}($arguments[0], $arguments[1]);
						break;
					case 3:
						$result = $this->{$methodName}($arguments[0], $arguments[1], $arguments[2]);
						break;
					case 4:
						$result = $this->{$methodName}($arguments[0], $arguments[1], $arguments[2], $arguments[3]);
						break;
					case 5:
						$result = $this->{$methodName}($arguments[0], $arguments[1], $arguments[2], $arguments[3], $arguments[4]);
						break;
					default:
						$result = call_user_func_array(array($this, $methodName), $arguments);
						break;
				}

				// Restore the old results and add the new result from our method call
				array_push($allResults, $result);
				$event['result'] = $allResults;
			}
		);
	}

	/**
	 * Registers a proper event listener, i.e. a method which accepts an AbstractEvent as its sole argument. This is the
	 * preferred way to implement plugins in Joomla! 4.x and will be the only possible method with Joomla! 5.x onwards.
	 *
	 * @param   string  $methodName  The method name to register
	 *
	 * @return  void
	 *
	 * @since   4.0
	 */
	protected final function registerListener($methodName)
	{
		$this->getDispatcher()->addListener($methodName, [$this, $methodName]);
	}
}