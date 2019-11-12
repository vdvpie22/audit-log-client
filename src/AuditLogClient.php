<?php

namespace abc\modules\audit_log;

use abc\core\ABC;
use abc\core\engine\Registry;
use abc\core\lib\ALog;
use abc\core\lib\contracts\AuditLogStorageInterface;
use AuditLog\AuditLogClient;
use AuditLog\AuditLogConfig;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use http\Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class AuditLogRabbitStorage implements AuditLogStorageInterface
{

    /**
     * @var ALog $log
     */
    private $log;

    /**
     * AuditLogRabbitStorage constructor.
     */
    public function __construct()
    {
        $this->log = Registry::log();
    }

    /**
     * Method for write Audit log data to storage (DB, ElasticSearch, etc)
     *
     * @param array $data
     *
     * @return mixed|void
     * @throws \Exception
     *
     */
    public function write(array $data)
    {
        $domain = ABC::env('AUDIT_LOG_API')['DOMAIN'];
        $data = [
            'data'   => $data,
            'domain' => $domain ?: 'audit-log-index',
        ];

        try {
            $conf = ABC::env('RABBIT_MQ');
            $conn = new AMQPStreamConnection($conf['HOST'], $conf['PORT'], $conf['USER'], $conf['PASSWORD']);
            $channel = $conn->channel();

            $channel->exchange_declare('exch_main', 'direct', false, true, false);
            $channel->exchange_declare('exch_backup', 'fanout', false, true, false);

            $channel->queue_declare('audit_log', false, true, false, false, false, new AMQPTable([
                'x-dead-letter-exchange' => 'exch_backup',
                'x-message-ttl'          => 15000,
                //'x-expires'              => 16000,
            ]));

            $channel->queue_declare('audit_log_backup', false, true, false, false, false, new AMQPTable([]));

            $channel->queue_bind('audit_log', 'exch_main');
            $channel->queue_bind('audit_log_backup', 'exch_backup');


            $msg = new AMQPMessage(json_encode($data));
            $channel->basic_publish($msg, '', $conf['QUEUE']);
            $channel->close();
            $conn->close();
        } catch (\Exception $exception) {
            if (!file_exists(ABC::env('DIR_SYSTEM').'rabbitmq')) {
                if (!mkdir($concurrentDirectory = ABC::env('DIR_SYSTEM').'rabbitmq', 0775, true) && !is_dir($concurrentDirectory)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                }
            }
            $this->log->write($exception->getMessage());
            $backupFile = ABC::env('DIR_SYSTEM').'rabbitmq/rabbit_data.bak';
            file_put_contents($backupFile, json_encode($data).PHP_EOL, FILE_APPEND);

        }
    }

    public function getEvents(array $request)
    {
        $api = ABC::env('AUDIT_LOG_API');
        $conf = new AuditLogConfig($api['HOST']);
        $client = new AuditLogClient($conf);
        try {
            $request = $this->prepareRequest($request);
            $events = $client->getEvents($api['DOMAIN'], $request);
            $result = [
                'items' => $this->prepareEvents($events['events']),
                'total' => $events['total'],
            ];
            return $result;
        } catch (Exception $exception) {
            $this->log->write($exception->getMessage());
        }
    }

    private function prepareRequest($request)
    {
        $allowSortBy = [
            'date_added'           => 'request.timestamp',
            'event'                => 'entity.group',
            'main_auditable_id'    => 'entity.id',
            'main_auditable_model' => 'entity.name',
            'user_name'            => 'actor.name',
        ];
        $filter = [];
        if (is_array($request['filter'])) {
            foreach ($request['filter'] as $item) {
                $item = json_decode($item, true);
                if (isset($request['user_name']) && !empty($request['user_name'])) {
                    $item['actor.name'] = $request['user_name'];
                }
                if (isset($request['events']) && !empty($request['events'])) {
                    $item['entity.group'] = $request['events'];
                    foreach ($item['entity.group'] as &$value) {
                        $value = strtolower($value);
                    }
                }
                foreach ($item as $key => $val) {
                    if (empty($val) && !is_array($val)) {
                        unset($item[$key]);
                    }
                }
                $item = json_encode($item);
                $item = str_replace('auditable_type', 'entity.name', $item);
                $item = str_replace('field_name', 'changes.name', $item);
                $item = str_replace('auditable_id', 'entity.id', $item);
                $filter[] = $item;
            }
        } else {
            $item = [];
            if (isset($request['user_name']) && !empty($request['user_name'])) {
                $item['actor.name'] = $request['user_name'];
            }
            if (isset($request['events']) && !empty($request['events'])) {
                $item['entity.group'] = $request['events'];
                foreach ($item['entity.group'] as &$value) {
                    $value = strtolower($value);
                }
            }
            $item = json_encode($item);
            $filter[] = $item;
        }
        $result = [
            'limit'  => (int)$request['rowsPerPage'],
            'offset' => (int)$request['rowsPerPage'] * (int)$request['page'] - (int)$request['rowsPerPage'],
            'sort'   => $allowSortBy[$request['sortBy']] ?: '',
            'order'  => $request['descending'] === 'true' ? 'DESC' : 'ASC',
        ];
        if (!empty($request['date_from'])) {
            $result['dateFrom'] = $request['date_from'];
        }
        if (!empty($request['date_to'])) {
            $result['dateTo'] = $request['date_to'];
        }
        if (is_array($filter) && !empty($filter)) {
            $result['filter'] = implode('||', $filter);
        }
        return $result;
    }

    private function prepareEvents($events)
    {
        $result = [];
        foreach ($events as $event) {
            $result[] = [
                'id'                   => $event['_id'],
                'user_name'            => $event['actor']['name'],
                'alias_name'           => '',
                'main_auditable_model' => $event['entity']['name'],
                'main_auditable_id'    => $event['entity']['id'],
                'event'                => $event['entity']['group'],
                'date_added'           => $event['request']['timestamp'],
            ];
        }
        return $result;
    }

    /**
     * * Method for get Audit log event description from storage (DB, ElasticSearch, etc)
     *
     * @param array $request
     *
     * @return mixed
     */
    public function getEventDetail(array $request)
    {
        $api = ABC::env('AUDIT_LOG_API');
        $conf = new AuditLogConfig($api['HOST']);
        $client = new AuditLogClient($conf);
        $filter = json_decode($request['filter'], true);
        try {
            $event = $client->getEventById($api['DOMAIN'], $filter['audit_event_id']);
            $result = [
                'items' => $this->prepareEventDescriptionRows($event['events']),
                'total' => $event['total'],
            ];
            return $result;
        } catch (Exception $exception) {
            $this->log->write($exception->getMessage());
        }
    }

    private function prepareEventDescriptionRows($events)
    {
        $result = [];

        foreach ($events as $event) {
            foreach ($event['changes'] as $change) {
                $result[] = [
                    'auditable_model' => $change['groupName'],
                    'field_name'      => $change['name'],
                    'old_value'       => $change['oldValue'],
                    'new_value'       => $change['newValue'],
                ];
            }
        }

        return $result;
    }

}