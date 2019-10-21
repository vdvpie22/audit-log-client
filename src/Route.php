<?php

namespace AuditLog;

class Route
{
    private $path;
    private $method;

    /**
     * Route constructor.
     *
     * @param $method
     * @param $path
     */
    public function __construct($method, $path)
    {
        $this->path = $path;
        $this->method = $method;
    }

    /**
     * @return mixed
     */
    public function path()
    {
        return $this->path;
    }

    /**
     * @return mixed
     */
    public function method()
    {
        return $this->method;
    }




}