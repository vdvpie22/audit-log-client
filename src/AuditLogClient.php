<?php

namespace AuditLog;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use JsonSchema\Validator;
use mysql_xdevapi\Exception;

class AuditLogClient
{
    /**
     * @var AuditLogConfig $config
     */
    protected $config;
    /**
     * @var Routes $routes
     */
    private $routes;
    /**
     * @var Client $httpClient
     */
    private $httpClient;

    public function __construct(AuditLogConfig $auditLogConfig)
    {
        $this->config = $auditLogConfig;
        $this->routes = new Routes();
        $this->httpClient = new Client([
            'base_uri' => $this->config->serverUrl(),
        ]);
    }

    public function addEvent(array $data)
    {
        echo 'addEvent';
    }

    public function getEvents(String $domain, array $data = [])
    {
        $route = $this->routes->getEvents();
        $response = $this->httpClient->get($route->path().'/'.$domain, ['query' => $data]);
        return $this->processResponse($response);
    }

    public function getEventById(String $domain, String $id)
    {
        $route = $this->routes->getEventById();
        $response = $this->httpClient->get($route->path().'/'.$domain.'/'.$id);
        return $this->processResponse($response);
    }

    private function getFullUrl(Route $route)
    {
        return $this->config->serverUrl().$route->path();
    }

    private function processResponse($response)
    {
        if ($response->getStatusCode() === 200) {
            $events = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
            $result['events'] = $events['items'];
            $result['total'] = $events['total'];
            $result['items_count'] = $events['items_count'];
            return $result;
        } else {
            throw new \RuntimeException('Server response with HTTP code: '.$response->getStatusCode());
        }
    }

    private function prepareHits(array $hits)
    {
        foreach ($hits as &$hit) {
            $hit = $hit['_source'];
        }
        return $hits;
    }

}