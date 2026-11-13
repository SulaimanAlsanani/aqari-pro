<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Run the MySQL 8 client without a shell or password-bearing argv.
 * Caller must put the application into maintenance mode and ensure no
 * other writer can modify the database or private attachments.
 */
final class MySqlSnapshot
{
    public static function export(string $target, string $privateDirectory): void
    {
        // Laravel may resolve DB_URL into discrete fields and omit the
        // original URL from the instantiated connection's configuration.
        // Inspect the declared config BEFORE constructing/connecting MySQL.
        if (!empty(config('database.connections.mysql.url'))) {
            throw new RuntimeException('DB_URL is not supported by this dump helper; configure discrete DB fields.');
        }
        $config=DB::connection('mysql')->getConfig();
        if (!empty($config['url'])) {
            throw new RuntimeException('DB_URL is not supported by this dump helper; configure discrete DB fields.');
        }
        $database=self::safeValue($config['database']??null,'database');
        // --single-transaction is only safe for transactional tables.
        // Run with writes blocked and refuse MyISAM/MEMORY tables.
        $tables=DB::connection('mysql')->select(
            'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$database,'BASE TABLE']
        );
        foreach ($tables as $table) {
            if (strtoupper((string)$table->ENGINE)!=='INNODB') {
                throw new RuntimeException('MySQL database contains non-transactional tables.');
            }
        }
        $username=self::safeValue($config['username']??null,'username');
        $password=self::safeValue($config['password']??'','password',true);
        $socket=(string)($config['unix_socket']??'');
        $host=(string)($config['host']??'');
        $port=(string)($config['port']??'3306');
        $ini="[client]\nuser=".self::iniQuote($username)."\npassword=".self::iniQuote($password)."\n";
        if ($socket!=='') {
            self::safeValue($socket,'socket');
            $ini.="socket=".self::iniQuote($socket)."\n";
        } else {
            self::safeValue($host,'host');
            if (!preg_match('/^[0-9]{1,5}$/D',$port) || (int)$port<1 || (int)$port>65535) {
                throw new RuntimeException('Invalid MySQL port.');
            }
            $ini.="host=".self::iniQuote($host)."\nport=".$port."\n";
        }

        $options=$privateDirectory.'/mysql-client.cnf';
        $handle=fopen($options,'xb');
        if ($handle===false) throw new RuntimeException('Could not create restricted MySQL credentials file.');
        try {
            chmod($options,0600);
            if (fwrite($handle,$ini)!==strlen($ini)) throw new RuntimeException('MySQL credential file write failed.');
        } finally {
            fclose($handle);
            unset($ini,$password);
        }

        try {
            $args=[
                'mysqldump','--defaults-extra-file='.$options,
                '--single-transaction','--quick','--skip-lock-tables',
                '--routines','--triggers','--events','--hex-blob',
                '--no-tablespaces','--set-gtid-purged=OFF',
                '--default-character-set=utf8mb4','--result-file='.$target,
                '--databases',$database,
            ];
            $process=new Process($args);
            $process->setTimeout(300);
            $process->run();
            if (!$process->isSuccessful()) {
                // CLI output can include sensitive details; do not surface it.
                throw new RuntimeException('mysqldump failed; check server-side restricted logs.');
            }
            if (!is_file($target) || filesize($target)===0) {
                throw new RuntimeException('MySQL dump is empty or missing.');
            }
        } finally {
            @unlink($options);
        }
    }

    private static function safeValue(mixed $value,string $label,bool $allowEmpty=false): string
    {
        if (!is_string($value) || (!$allowEmpty && $value==='') ||
            preg_match('/[\x00-\x1F\x7F]/',$value)) {
            throw new RuntimeException('Invalid MySQL '.$label.' configuration.');
        }
        return $value;
    }

    private static function iniQuote(string $value): string
    {
        return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
    }
}
