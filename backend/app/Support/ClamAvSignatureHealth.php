<?php
namespace App\Support;

/**
 * Offline checks of local ClamAV main/daily signature files.
 *
 * The daily file modification time is a conservative freshness heuristic:
 * the functional scanner probe is still required before service readiness.
 */
final class ClamAvSignatureHealth
{
    /**
     * @return array{main:bool,daily:bool,daily_fresh:bool}
     */
    public static function inspect(string $directory, int $now, int $maxDailyAgeHours=168): array
    {
        $main=self::filesWithContent($directory,['main.cvd','main.cld']);
        $daily=self::filesWithContent($directory,['daily.cvd','daily.cld']);
        $maxAge=max(1,$maxDailyAgeHours)*3600;
        $fresh=false;

        foreach ($daily as $path) {
            $modified=@filemtime($path);
            if ($modified!==false && $modified<=$now+300 &&
                $modified>=$now-$maxAge) {
                $fresh=true;
                break;
            }
        }

        return [
            'main'=>count($main)>0,
            'daily'=>count($daily)>0,
            'daily_fresh'=>$fresh,
        ];
    }

    /**
     * @return list<string>
     */
    private static function filesWithContent(string $directory, array $names): array
    {
        $existing=[];
        foreach ($names as $name) {
            $file=rtrim($directory,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
            if (is_file($file) && is_readable($file) &&
                ($size=@filesize($file))!==false && $size>0) {
                $existing[]=$file;
            }
        }
        return $existing;
    }
}
