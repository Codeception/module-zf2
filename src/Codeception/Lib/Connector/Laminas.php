<?php

namespace Codeception\Lib\Connector;

use Exception;
use Laminas\Http\Headers as HttpHeaders;
use Laminas\Http\PhpEnvironment\Request as LaminasRequest;
use Laminas\Http\Request as HttpRequest;
use Laminas\Mvc\Application;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Stdlib\Parameters;
use Laminas\Uri\Http as HttpUri;
use Symfony\Component\BrowserKit\AbstractBrowser as Client;
use Symfony\Component\BrowserKit\Request as BrowserKitRequest;
use Symfony\Component\BrowserKit\Response;

class Laminas extends Client
{
    /** @var ApplicationInterface */
    protected $application;

    protected $applicationConfig = [];

    /** @var LaminasRequest */
    protected $laminasRequest;

    private $persistentServices = [];

    public function setApplicationConfig(array $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;

        $this->createApplication();
    }

    /**
     * @param BrowserKitRequest $request
     *
     * @return Response
     *
     * @throws Exception
     */
    public function doRequest($request): Response
    {
        $this->createApplication();

        $zendRequest = $this->application->getRequest();
        $uri         = new HttpUri($request->getUri());
        $queryString = $uri->getQuery();
        $method      = \strtoupper($request->getMethod());
        $query       = [];
        $post        = [];
        $content     = $request->getContent();

        if ($queryString) {
            \parse_str($queryString, $query);
        }

        if ($method !== HttpRequest::METHOD_GET) {
            $post = $request->getParameters();
        }

        $zendRequest->setCookies(new Parameters($request->getCookies()));
        $zendRequest->setServer(new Parameters($request->getServer()));
        $zendRequest->setQuery(new Parameters($query));
        $zendRequest->setPost(new Parameters($post));
        $zendRequest->setFiles(new Parameters($request->getFiles()));
        $zendRequest->setContent(\is_null($content) ? '' : $content);
        $zendRequest->setMethod($method);
        $zendRequest->setUri($uri);

        $requestUri = $uri->getPath();

        if (!empty($queryString)) {
            $requestUri .= '?' . $queryString;
        }

        $zendRequest->setRequestUri($requestUri);

        $zendRequest->setHeaders($this->extractHeaders($request));

        $this->application->run();

        // get the response *after* the application has run, because other Laminas
        //     libraries like API Agility may *replace* the application's response
        //
        $zendResponse = $this->application->getResponse();

        $this->laminasRequest = $zendRequest;

        $exception = $this->application->getMvcEvent()->getParam('exception');
        if ($exception instanceof Exception) {
            throw $exception;
        }

        return new Response(
            $zendResponse->getBody(),
            $zendResponse->getStatusCode(),
            $zendResponse->getHeaders()->toArray()
        );
    }

    public function getLaminasRequest(): LaminasRequest
    {
        return $this->laminasRequest;
    }

    /**
     * @param string $service
     *
     * @return mixed
     */
    public function grabServiceFromContainer(string $service)
    {
        $serviceManager = $this->application->getServiceManager();

        if (!$serviceManager->has($service)) {
            throw new \PHPUnit\Framework\AssertionFailedError("Service $service is not available in container");
        }

        return $serviceManager->get($service);
    }

    public function persistService(string $name): void
    {
        $service                         = $this->grabServiceFromContainer($name);
        $this->persistentServices[$name] = $service;
    }

    /**
     * @param string $name
     * @param mixed  $service
     *
     * @return void
     */
    public function addServiceToContainer(string $name, $service): void
    {
        $this->application->getServiceManager()->setAllowOverride(true);
        $this->application->getServiceManager()->setService($name, $service);
        $this->application->getServiceManager()->setAllowOverride(false);

        $this->persistentServices[$name] = $service;
    }

    private function extractHeaders(BrowserKitRequest $request): HttpHeaders
    {
        $headers        = [];
        $server         = $request->getServer();
        $contentHeaders = ['Content-Length' => true, 'Content-Md5' => true, 'Content-Type' => true];

        foreach ($server as $header => $val) {
            $header = \html_entity_decode(
                \implode(
                    '-',
                    \array_map(
                        'ucfirst',
                        \explode(
                            '-',
                            \strtolower(\str_replace('_', '-', $header))
                        )
                    )
                ),
                ENT_NOQUOTES
            );

            if (\strpos($header, 'Http-') === 0) {
                $headers[\substr($header, 5)] = $val;
            } elseif (isset($contentHeaders[$header])) {
                $headers[$header] = $val;
            }
        }

        $zendHeaders = new HttpHeaders();
        $zendHeaders->addHeaders($headers);

        return $zendHeaders;
    }

    private function createApplication(): void
    {
        $this->application = Application::init(
            ArrayUtils::merge(
                $this->applicationConfig,
                [
                    'service_manager' => [
                        'services' => $this->persistentServices
                    ]
                ]
            )
        );

        $serviceManager       = $this->application->getServiceManager();
        $sendResponseListener = $serviceManager->get('SendResponseListener');
        $events               = $this->application->getEventManager();

        $events->detach([$sendResponseListener, 'sendResponse']);
    }
}
