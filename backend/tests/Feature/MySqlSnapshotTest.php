<?php
namespace Tests\Feature;

use App\Support\MySqlSnapshot;
use RuntimeException;
use Tests\TestCase;

final class MySqlSnapshotTest extends TestCase
{
    public function test_mysql_dump_refuses_ambiguous_connection_url(): void
    {
        config()->set('database.connections.mysql.url','mysql://someone:secret@localhost/mydb');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_URL');
        // Must fail before launching a MySQL process or creating credentials.
        MySqlSnapshot::export(sys_get_temp_dir().'/aqari-unused-dump.sql',sys_get_temp_dir());
    }
}
