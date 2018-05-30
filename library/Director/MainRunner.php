<?php

namespace Icinga\Module\Director;

use Exception;
use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Application\Platform;
use React\EventLoop\Factory as Loop;
use React\EventLoop\LoopInterface;
use Throwable;

class MainRunner
{
    /** @var LoopInterface */
    private $loop;

    private $isReady = false;

    /** @var Db */
    private $connection;

    /** @var string */
    protected $dbResourceName;

    /** @var bool */
    protected $useDefaultConnection = true;

    /** @var bool */
    protected $showTrace = false;

    public function setConnection(Db $connection)
    {
        $this->connection = $connection;
        $this->useDefaultConnection = false;

        return $this;
    }

    public function run()
    {
        $loop = $this->loop = Loop::create();
        $loop->addSignal(SIGINT, $func = function ($signal) use (&$func) {
            $this->shutdownWithSignal($signal, $func);
        });
        $loop->addSignal(SIGTERM, $func = function ($signal) use (&$func) {
            $this->shutdownWithSignal($signal, $func);
        });

        $loop->futureTick(function () {
            $this->isReady = true;
            $this->runFailSafe(function () {
                $this->initialize();
            });
        });
        $loop->addPeriodicTimer(5, function () {
            $this->runFailSafe(function () {
                $this->refreshMyState();
            });
        });
        $loop->addPeriodicTimer(16, function () {
            if (! $this->isReady) {
                $this->reset();
            }
        });
        $loop->run();
    }

    public function showTrace($show = true)
    {
        $this->showTrace = (bool) $show;

        return $this;
    }

    /**
     * @throws \Zend_Db_Adapter_Exception
     */
    protected function initialize()
    {
        Logger::debug('MainRunner::initialize()');
        if ($this->useDefaultConnection) {
            $this->connection = null;
            $this->connection = Db::newConfiguredInstance();
        }

        $this->updateMyState();
        Logger::info(
            'MainRunner has been initialized',
            $this->getLogName()
        );
    }

    /**
     * @throws \Zend_Db_Adapter_Exception
     */
    protected function refreshMyState()
    {
        $db = $this->connection->getDbAdapter();
        $updated = $db->update('director_daemon', [
            'ts_last_refresh' => Util::currentTimestamp()
        ]);

        if (! $updated) {
            $this->insertMyState();
        }
    }

    /**
     * @throws \Zend_Db_Adapter_Exception
     */
    protected function updateMyState()
    {
        $db = $this->connection->getDbAdapter();
        $updated = $db->update('director_daemon', [
            'ts_last_refresh' => Util::currentTimestamp(),
            'pid'             => posix_getpid(),
            'fqdn'            => Platform::getFqdn(),
            'username'        => Platform::getPhpUser(),
            'php_version'     => Platform::getPhpVersion(),
        ], $db->quoteInto('db_name = ?', $this->getDbResourceName()));

        if (! $updated) {
            $this->insertMyState();
        }
    }

    /**
     * @throws \Zend_Db_Adapter_Exception
     */
    protected function insertMyState()
    {
        $db = $this->connection->getDbAdapter();
        $db->insert('director_daemon', [
            'resource_name'   => $this->getDbResourceName(),
            'ts_last_refresh' => Util::currentTimestamp(),
            'pid'             => posix_getpid(),
            'fqdn'            => Platform::getFqdn(),
            'username'        => Platform::getPhpUser(),
            'php_version'     => Platform::getPhpVersion(),
        ]);
    }

    protected function closeDbConnection()
    {
        if ($this->connection !== null) {
            $this->connection->getDbAdapter()->closeConnection();
            Logger::debug(
                'Closed database connection for %s',
                $this->getLogName()
            );
        }
    }

    protected function reset()
    {
        $this->isReady = false;
        try {
            Logger::info(
                'Resetting Director main runner for %s',
                $this->getLogName()
            );
            $this->eventuallyLogout();
            $this->closeDbConnection();
            $this->initialize();
            $this->isReady = true;
        } catch (Exception $e) {
            Logger::error(
                'Failed to reset Director main runner for %s',
                $this->getLogName()
            );

            $this->logException($e);
        }
    }

    protected function shutdownWithSignal($signal, &$func)
    {
        $this->loop->removeSignal($signal, $func);
        $this->shutdown();
    }

    protected function shutdown()
    {
        $this->isReady = false;
        try {
            Logger::info(
                'Shutting down Director main runner for %s',
                $this->getLogName()
            );
            $this->eventuallyLogout();
            $this->closeDbConnection();
        } catch (Exception $e) {
            Logger::error(
                'Failed to safely shutdown Director main runner for %s: %s -> %s, stopping anyways',
                $this->getLogName()
            );
            $this->logException($e);
        }

        $this->loop->stop();
    }

    protected function eventuallyLogout()
    {
    }

    public function getDbResourceName()
    {
        if ($this->dbResourceName === null) {
            $this->dbResourceName = Config::module('director')
                ->get('db', 'resource');
        }

        return $this->dbResourceName;
    }

    protected function getLogName()
    {
        // return sprintf('icinga-director  db=%d', $this->vCenterId);
        return 'icinga-director';
    }

    protected function runFailSafe($method)
    {
        if (! $this->isReady) {
            return;
        }

        try {
            $method();
        } catch (Throwable $e) {
            $this->logException($e)->reset();
        } catch (Exception $e) {
            $this->logException($e)->reset();
        }
    }

    /**
     * @param Throwable|Exception $e
     * @return $this
     */
    protected function logException($e)
    {
        Logger::error($e->getMessage());
        if ($this->showTrace) {
            Logger::error($e->getTraceAsString());
        }

        return $this;
    }

    protected function loop()
    {
        if ($this->loop === null) {
            $this->loop = Loop::create();
        }

        return $this->loop;
    }
}
