<?php

namespace AuditLog;

class AuditLogConfig
{
    protected $serverUrl;
    protected $login;
    protected $password;

    /**
     * AuditLogConfig constructor.
     *
     * @param $serverUrl
     * @param $login
     * @param $password
     */
    public function __construct($serverUrl, $login='', $password='')
    {
        $this->serverUrl = $serverUrl;
        $this->login = $login;
        $this->password = $password;
    }

    public function serverUrl()
    {
        return $this->serverUrl;
    }

}