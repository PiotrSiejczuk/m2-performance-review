<?php
namespace M2Performance\Utils;

class SocketChecker
{
    public static function check(string $host, int $port): bool
    {
        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($socket) {
                fclose($socket);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
