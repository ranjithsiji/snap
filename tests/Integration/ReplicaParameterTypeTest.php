<?php

declare(strict_types=1);

namespace JuryTool\Tests\Integration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The parameter shapes ReplicaClient binds with.
 *
 * ReplicaClient only ever runs against the Wikimedia replicas, so nothing
 * in the ordinary test run touches it: it shipped binding \PDO::PARAM_*
 * constants, which DBAL 4 rejects outright, and every import on Toolforge
 * failed before reading its first file. The queries here mirror its
 * binding shape — that is the part that broke, and it breaks with any
 * table.
 */
#[Group('integration')]
class ReplicaParameterTypeTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $name = getenv('TEST_DB_NAME');

        if ($name === false || $name === '') {
            $this->markTestSkipped('Set TEST_DB_NAME (and TEST_DB_USER etc.) to run the DBAL binding tests.');
        }

        // DBAL 4 takes explicit parameters; the `url` shorthand is gone.
        $this->connection = DriverManager::getConnection([
            'driver' => getenv('TEST_DB_DRIVER') ?: 'pdo_mysql',
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'dbname' => $name,
            'user' => getenv('TEST_DB_USER') ?: '',
            'password' => getenv('TEST_DB_PASSWORD') ?: '',
        ]);
        $this->connection->executeStatement(
            'CREATE TEMPORARY TABLE replica_param_probe (id INT NOT NULL, title VARCHAR(64) NOT NULL)'
        );

        foreach ([[1, 'Alpha'], [2, 'Beta'], [3, 'Gamma']] as [$id, $title]) {
            $this->connection->executeStatement(
                'INSERT INTO replica_param_probe (id, title) VALUES (?, ?)',
                [$id, $title],
            );
        }
    }

    /**
     * streamFilesInCategory's shape: named scalars carrying explicit types,
     * with the row limit bound rather than interpolated.
     */
    public function testTypedScalarParametersBind(): void
    {
        $rows = $this->connection->executeQuery(
            'SELECT id, title FROM replica_param_probe
             WHERE title = :title AND id > :after ORDER BY id ASC LIMIT :batch',
            ['title' => 'Gamma', 'after' => 0, 'batch' => 10],
            [
                'title' => ParameterType::STRING,
                'after' => ParameterType::INTEGER,
                'batch' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        self::assertSame([['id' => 3, 'title' => 'Gamma']], $rows);
    }

    /** filesByTitle's shape: a list expanded into an IN clause. */
    public function testArrayParameterExpands(): void
    {
        $titles = $this->connection->executeQuery(
            'SELECT title FROM replica_param_probe WHERE title IN (:titles) ORDER BY id ASC',
            ['titles' => ['Alpha', 'Gamma']],
            ['titles' => ArrayParameterType::STRING],
        )->fetchFirstColumn();

        self::assertSame(['Alpha', 'Gamma'], $titles);
    }

    /**
     * The regression itself. \PDO::PARAM_* are plain ints, and DBAL 4 wants
     * a ParameterType — so binding them raises a TypeError rather than
     * quietly doing the wrong thing. Pinned so a well-meaning revert to the
     * familiar constants fails here instead of on Toolforge.
     */
    public function testPdoConstantsAreRejected(): void
    {
        $this->expectException(\TypeError::class);

        $this->connection->executeQuery(
            'SELECT id FROM replica_param_probe
             WHERE title = :title AND id > :after ORDER BY id ASC LIMIT :batch',
            ['title' => 'Alpha', 'after' => 0, 'batch' => 1],
            [
                'title' => \PDO::PARAM_STR,
                'after' => \PDO::PARAM_INT,
                'batch' => \PDO::PARAM_INT,
            ],
        )->fetchOne();
    }
}
