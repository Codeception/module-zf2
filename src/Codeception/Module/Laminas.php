<?php

namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Lib\Connector\Laminas as LaminasConnector;
use Codeception\Lib\Framework;
use Codeception\Lib\Interfaces\DoctrineProvider;
use Codeception\Lib\Interfaces\PartedModule;
use Codeception\TestInterface;
use Codeception\Util\ReflectionHelper;
use Exception;
use Laminas\Console\Console;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Router\Http\Hostname;
use Laminas\Router\Http\Part;
use Laminas\Router\Http\TreeRouteStack;
use Traversable;

/**
 * This module allows you to run tests inside the Laminas Project.
 * Uses `tests/application.config.php` config file by default.
 *
 * Note: services part and Doctrine integration is not compatible with Laminas yet
 *
 * ## Status
 *
 * * Maintainer: **Naktibalda**
 * * Stability: **stable**
 *
 * ## Config
 *
 * * config: relative path to config file (default: `tests/application.config.php`)
 * * em_service: 'Doctrine\ORM\EntityManager' - use the stated EntityManager to pair with Doctrine Module.
 *
 * ## Public Properties
 * * application -  instance of `\Laminas\Mvc\ApplicationInterface`
 * * db - instance of `\Laminas\Db\Adapter\AdapterInterface`
 * * client - BrowserKit client
 *
 * ## Parts
 *
 * * services - allows to use grabServiceFromContainer and addServiceToContainer with WebDriver or PhpBrowser modules.
 *
 * Usage example:
 *
 * ```yaml
 * actor: AcceptanceTester
 * modules:
 *     enabled:
 *         - Laminas:
 *             part: services
 *         - Doctrine2:
 *             depends: Laminas
 *         - WebDriver:
 *             url: http://your-url.com
 *             browser: phantomjs
 * ```
 */
class Laminas extends Framework implements DoctrineProvider, PartedModule
{
    /** @var ApplicationInterface */
    public $application;

    /** @var AdapterInterface */
    public $db;

    /** @var LaminasConnector */
    public $client;

    /** @var string[] */
    protected $config = [
        'config'     => 'tests/application.config.php',
        'em_service' => 'Doctrine\ORM\EntityManager',
    ];

    protected $applicationConfig = [];
    protected $queries           = 0;
    protected $time              = 0;

    /**
     * @var array
     *
     * Used to collect domains while recursively traversing route tree
     */
    private $domainCollector = [];

    public function _initialize(): void
    {
        $initAutoloaderFile = Configuration::projectDir() . 'init_autoloader.php';
        if (\file_exists($initAutoloaderFile)) {
            require $initAutoloaderFile;
        }

        $this->applicationConfig = require Configuration::projectDir() . $this->config['config'];
        if (isset($this->applicationConfig['module_listener_options']['config_cache_enabled'])) {
            $this->applicationConfig['module_listener_options']['config_cache_enabled'] = false;
        }
        if (\class_exists(Console::class)) {
            Console::overrideIsConsole(false);
        }

        //grabServiceFromContainer may need client in beforeClass hooks of modules or helpers
        $this->client = new LaminasConnector();
        $this->client->setApplicationConfig($this->applicationConfig);
    }

    public function _before(TestInterface $test): void
    {
        $this->client = new LaminasConnector();
        $this->client->setApplicationConfig($this->applicationConfig);
        $_SERVER['REQUEST_URI'] = '';
    }

    public function _after(TestInterface $test): void
    {
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        $_COOKIE  = [];

        $this->queries = 0;
        $this->time    = 0;

        parent::_after($test);
    }

    public function _parts(): array
    {
        return ['services'];
    }

    public function _afterSuite(): void
    {
        unset($this->client);
    }

    public function _getEntityManager()
    {
        if (!$this->client) {
            $this->fail('Laminas module is not loaded');
        }

        $this->client->persistService($this->config['em_service']);

        return $this->grabServiceFromContainer($this->config['em_service']);
    }

    /**
     * Grabs a service from a Laminas container.
     * Recommended to use for unit testing.
     * ``` php
     * <?php
     * $em = $I->grabServiceFromContainer('Doctrine\ORM\EntityManager');
     * ?>
     * ```
     * @part services
     *
     * @param string $service
     *
     * @return mixed
     */
    public function grabServiceFromContainer(string $service)
    {
        return $this->client->grabServiceFromContainer($service);
    }

    /**
     * Adds service to a Laminas container
     *
     * @part services
     *
     * @param string $name
     * @param object $service
     *
     * @return void
     */
    public function addServiceToContainer(string $name, $service): void
    {
        $this->client->addServiceToContainer($name, $service);
    }

    /**
     * Opens web page using route name and parameters.
     * ``` php
     * <?php
     * $I->amOnRoute('posts.create');
     * $I->amOnRoute('posts.show', array('id' => 34));
     * ?>
     * ```
     *
     * @param string $routeName
     * @param array  $params
     *
     * @return void
     */
    public function amOnRoute(string $routeName, array $params = []): void
    {
        $router = $this->client->grabServiceFromContainer('router');
        $url    = $router->assemble($params, ['name' => $routeName]);

        $this->amOnPage($url);
    }

    /**
     * Checks that current url matches route.
     * ``` php
     * <?php
     * $I->seeCurrentRouteIs('posts.index');
     * $I->seeCurrentRouteIs('posts.show', ['id' => 8]));
     * ?>
     * ```
     *
     * @param string $routeName
     * @param array  $params
     *
     * @return void
     */
    public function seeCurrentRouteIs(string $routeName, array $params = []): void
    {
        $router = $this->client->grabServiceFromContainer('router');
        $url    = $router->assemble($params, ['name' => $routeName]);

        $this->seeCurrentUrlEquals($url);
    }

    protected function getInternalDomains(): array
    {
        /** @var TreeRouteStack $router */
        $router                = $this->client->grabServiceFromContainer('router');
        $this->domainCollector = [];

        $this->addInternalDomainsFromRoutes($router->getRoutes());

        return \array_unique($this->domainCollector);
    }

    private function addInternalDomainsFromRoutes(Traversable $routes): void
    {
        foreach ($routes as $name => $route) {
            if ($route instanceof Hostname) {
                $this->addInternalDomain($route);
            } elseif ($route instanceof Part) {
                $parentRoute = ReflectionHelper::readPrivateProperty($route, 'route');
                if ($parentRoute instanceof Hostname) {
                    $this->addInternalDomain($parentRoute);
                }
                // this is necessary to instantiate child routes
                try {
                    $route->assemble([], []);
                } catch (Exception $e) {
                }
                $this->addInternalDomainsFromRoutes($route->getRoutes());
            }
        }
    }

    /**
     * @param object $route
     *
     * @return void
     */
    private function addInternalDomain($route): void
    {
        $regex                    = ReflectionHelper::readPrivateProperty($route, 'regex');
        $this->domainCollector [] = '/^' . $regex . '$/';
    }
}
