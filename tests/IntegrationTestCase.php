<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Core\Db;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Data\Database;

abstract class IntegrationTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        /** @var DatabaseConfig $config */
        $config = $GLOBALS['TEST_DATABASE_CONFIG'];
        $this->db = Db::fromConfig($config);
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }
}
