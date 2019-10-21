<?php

namespace AuditLog;

class Routes
{
    private $getEvents;
    private $createEvent;
    private $getEventById;

    /**
     * Routes constructor.
     *
     */
    public function __construct()
    {
        $this->getEvents = new Route('POST', 'events');
        $this->createEvent = new Route('POST', 'event');
        $this->getEventById = new Route('GET', 'event');
    }

    /**
     * @return Route
     */
    public function getEvents()
    {
        return $this->getEvents;
    }

    /**
     * @return Route
     */
    public function createEvent()
    {
        return $this->createEvent;
    }

    /**
     * @return Route
     */
    public function getEventById()
    {
        return $this->getEventById;
    }


}