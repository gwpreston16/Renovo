<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Persistence\Database;
use App\Repository\BackupDumper;

/**
 * Where a restore rehearsal gets its dumper for the scratch database: a seam,
 * so a test can hand it one that reads back a different row count.
 */
class BackupDumperFactory
{
    public function forDatabase(Database $db): BackupDumper
    {
        return new BackupDumper($db);
    }
}
