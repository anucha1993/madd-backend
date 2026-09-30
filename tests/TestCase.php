<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard stop before any test (and RefreshDatabase's migrate:fresh) can touch a real
     * database: .env points at the shared MySQL server, and a DB_CONNECTION set in the shell
     * overrides phpunit.xml's sqlite even with force="true". Tests only ever run on the
     * in-memory sqlite database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException("Refusing to run tests on database connection [{$connection}] / [{$database}] — tests must use in-memory sqlite (see phpunit.xml). Unset DB_CONNECTION / DB_DATABASE in your shell.");
        }

        return $app;
    }
}
