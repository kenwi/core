<?php
/**
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <rmccorkell@karoshi.org.uk>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Route;

use OCP\ILogger;
use OCP\Route\IRouter;
use OCP\AppFramework\App;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class Router implements IRouter {
	/**
	 * @var \Symfony\Component\Routing\RouteCollection[]
	 */
	protected $collections = array();

	/**
	 * @var \Symfony\Component\Routing\RouteCollection
	 */
	protected $collection = null;

	/**
	 * @var string
	 */
	protected $collectionName = null;

	/**
	 * @var \Symfony\Component\Routing\RouteCollection
	 */
	protected $root = null;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGenerator
	 */
	protected $generator = null;

	/**
	 * @var string[]
	 */
	protected $routingFiles;

	/**
	 * @var string
	 */
	protected $cacheKey;

	protected $loaded = false;

	protected $loadedApps = array();

	/**
	 * @var ILogger
	 */
	protected $logger;

	/**
	 * @param ILogger $logger
	 */
	public function __construct(ILogger $logger) {
		$this->logger = $logger;
		$baseUrl = \OC::$WEBROOT;
		if(!(getenv('front_controller_active') === 'true')) {
			$baseUrl = \OC_Helper::linkTo('', 'index.php');
		}
		if (!\OC::$CLI) {
			$method = $_SERVER['REQUEST_METHOD'];
		} else {
			$method = 'GET';
		}
		$request = \OC::$server->getRequest();
		$host = $request->getServerHost();
		$schema = $request->getServerProtocol();
		$this->context = new RequestContext($baseUrl, $method, $host, $schema);
		// TODO cache
		$this->root = $this->getCollection('root');
	}

	/**
	 * Get the files to load the routes from
	 *
	 * @return string[]
	 */
	public function getRoutingFiles() {
		if (!isset($this->routingFiles)) {
			$this->routingFiles = array();
			foreach (\OC_APP::getEnabledApps() as $app) {
				$file = \OC_App::getAppPath($app) . '/appinfo/routes.php';
				if (file_exists($file)) {
					$this->routingFiles[$app] = $file;
				}
			}
		}
		return $this->routingFiles;
	}

	/**
	 * @return string
	 */
	public function getCacheKey() {
		if (!isset($this->cacheKey)) {
			$files = $this->getRoutingFiles();
			$files[] = 'settings/routes.php';
			$files[] = 'core/routes.php';
			$files[] = 'ocs/routes.php';
			$this->cacheKey = \OC\Cache::generateCacheKeyFromFiles($files);
		}
		return $this->cacheKey;
	}

	/**
	 * loads the api routes
	 *
	 * @return void
	 */
	public function loadRoutes($app = null) {
		$requestedApp = $app;
		if ($this->loaded) {
			return;
		}
		if (is_null($app)) {
			$this->loaded = true;
			$routingFiles = $this->getRoutingFiles();
		} else {
			if (isset($this->loadedApps[$app])) {
				return;
			}
			$file = \OC_App::getAppPath($app) . '/appinfo/routes.php';
			if (file_exists($file)) {
				$routingFiles = array($app => $file);
			} else {
				$routingFiles = array();
			}
		}
		\OC::$server->getEventLogger()->start('loadroutes' . $requestedApp, 'Loading Routes');
		foreach ($routingFiles as $app => $file) {
			if (!isset($this->loadedApps[$app])) {
				if (!\OC_App::isAppLoaded($app)) {
					// app MUST be loaded before app routes
					// try again next time loadRoutes() is called
					$this->loaded = false;
					continue;
				}
				$this->loadedApps[$app] = true;
				$this->useCollection($app);
				$this->requireRouteFile($file, $app);
				$collection = $this->getCollection($app);
				$collection->addPrefix('/apps/' . $app);
				$this->root->addCollection($collection);
			}
		}
		if (!isset($this->loadedApps['core'])) {
			$this->loadedApps['core'] = true;
			$this->useCollection('root');
			require_once 'settings/routes.php';
			require_once 'core/routes.php';
		}
		if ($this->loaded) {
			// include ocs routes, must be loaded last for /ocs prefix
			require_once 'ocs/routes.php';
			$collection = $this->getCollection('ocs');
			$collection->addPrefix('/ocs');
			$this->root->addCollection($collection);
		}
		\OC::$server->getEventLogger()->end('loadroutes' . $requestedApp);
	}

	/**
	 * @param string $name
	 * @return \Symfony\Component\Routing\RouteCollection
	 */
	protected function getCollection($name) {
		if (!isset($this->collections[$name])) {
			$this->collections[$name] = new RouteCollection();
		}
		return $this->collections[$name];
	}

	/**
	 * Sets the collection to use for adding routes
	 *
	 * @param string $name Name of the collection to use.
	 * @return void
	 */
	public function useCollection($name) {
		$this->collection = $this->getCollection($name);
		$this->collectionName = $name;
	}

	/**
	 * returns the current collection name in use for adding routes
	 *
	 * @return string the collection name
	 */
	public function getCurrentCollection() {
		return $this->collectionName;
	}


	/**
	 * Create a \OC\Route\Route.
	 *
	 * @param string $name Name of the route to create.
	 * @param string $pattern The pattern to match
	 * @param array $defaults An array of default parameter values
	 * @param array $requirements An array of requirements for parameters (regexes)
	 * @return \OC\Route\Route
	 */
	public function create($name, $pattern, array $defaults = array(), array $requirements = array()) {
		$route = new Route($pattern, $defaults, $requirements);
		$this->collection->add($name, $route);
		return $route;
	}

	/**
	 * Find the route matching $url
	 *
	 * @param string $url The url to find
	 * @throws \Exception
	 * @return void
	 */
	public function match($url) {
		if (substr($url, 0, 6) === '/apps/') {
			// empty string / 'apps' / $app / rest of the route
			list(, , $app,) = explode('/', $url, 4);

			$app = \OC_App::cleanAppId($app);
			\OC::$REQUESTEDAPP = $app;
			$this->loadRoutes($app);
		} else if (substr($url, 0, 6) === '/core/' or substr($url, 0, 10) === '/settings/') {
			\OC::$REQUESTEDAPP = $url;
			if (!\OC::$server->getConfig()->getSystemValue('maintenance', false) && !\OCP\Util::needUpgrade()) {
				\OC_App::loadApps();
			}
			$this->loadRoutes('core');
		} else {
			$this->loadRoutes();
		}

		$matcher = new UrlMatcher($this->root, $this->context);
		try {
			$parameters = $matcher->match($url);
		} catch (ResourceNotFoundException $e) {
			if (substr($url, -1) !== '/') {
				// We allow links to apps/files? for backwards compatibility reasons
				// However, since Symfony does not allow empty route names, the route
				// we need to match is '/', so we need to append the '/' here.
				try {
					$parameters = $matcher->match($url . '/');
				} catch (ResourceNotFoundException $newException) {
					// If we still didn't match a route, we throw the original exception
					throw $e;
				}
			} else {
				throw $e;
			}
		}

		\OC::$server->getEventLogger()->start('run_route', 'Run route');
		if (isset($parameters['action'])) {
			$action = $parameters['action'];
			if (!is_callable($action)) {
				throw new \Exception('not a callable action');
			}
			unset($parameters['action']);
			call_user_func($action, $parameters);
		} elseif (isset($parameters['file'])) {
			include $parameters['file'];
		} else {
			throw new \Exception('no action available');
		}
		\OC::$server->getEventLogger()->end('run_route');
	}

	/**
	 * Get the url generator
	 *
	 * @return \Symfony\Component\Routing\Generator\UrlGenerator
	 *
	 */
	public function getGenerator() {
		if (null !== $this->generator) {
			return $this->generator;
		}

		return $this->generator = new UrlGenerator($this->root, $this->context);
	}

	/**
	 * Generate url based on $name and $parameters
	 *
	 * @param string $name Name of the route to use.
	 * @param array $parameters Parameters for the route
	 * @param bool $absolute
	 * @return string
	 */
	public function generate($name, $parameters = array(), $absolute = false) {
		$this->loadRoutes();
		try {
			return $this->getGenerator()->generate($name, $parameters, $absolute);
		} catch (RouteNotFoundException $e) {
			$this->logger->logException($e);
			return '';
		}
	}

	/**
	 * To isolate the variable scope used inside the $file it is required in it's own method
	 *
	 * @param string $file the route file location to include
	 * @param string $appName
	 */
	private function requireRouteFile($file, $appName) {
		$this->setupRoutes(include_once $file, $appName);
	}


	/**
	 * If a routes.php file returns an array, try to set up the application and
	 * register the routes for the app. The application class will be chosen by
	 * camelcasing the appname, e.g.: my_app will be turned into
	 * \OCA\MyApp\AppInfo\Application. If that class does not exist, a default
	 * App will be intialized. This makes it optional to ship an
	 * appinfo/application.php by using the built in query resolver
	 *
	 * @param array $routes the application routes
	 * @param string $appName the name of the app.
	 */
	private function setupRoutes($routes, $appName) {
		if (is_array($routes)) {
			$appNameSpace = App::buildAppNamespace($appName);

			$applicationClassName = $appNameSpace . '\\AppInfo\\Application';

			if (class_exists($applicationClassName)) {
				$application = new $applicationClassName();
			} else {
				$application = new App($appName);
			}

			$application->registerRoutes($this, $routes);
		}
	}


}
