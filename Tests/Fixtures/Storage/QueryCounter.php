<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures\Storage;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** Counts the statements the tests' connection runs: what a query budget is asserted against. */
final class QueryCounter implements Middleware
{
    public static int $queries = 0;

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            #[\Override]
            public function connect(array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    #[\Override]
                    public function prepare(string $sql): Statement
                    {
                        ++QueryCounter::$queries;

                        return parent::prepare($sql);
                    }

                    #[\Override]
                    public function query(string $sql): Result
                    {
                        ++QueryCounter::$queries;

                        return parent::query($sql);
                    }

                    #[\Override]
                    public function exec(string $sql): int|string
                    {
                        ++QueryCounter::$queries;

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
