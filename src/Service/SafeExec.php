<?php

declare(strict_types=1);

namespace M2Performance\Service;

/**
 * Shell execution with mandatory timeouts.
 *
 * Prevents a single wedged external command (n98-magerun2 against a large
 * database, find over an NFS mount, varnishstat on a stalled instance)
 * from hanging the entire analysis run.
 */
class SafeExec
{
    public const DEFAULT_TIMEOUT = 10;

    private static ?bool $hasTimeoutBinary = null;

    /**
     * Execute a command with a hard timeout.
     *
     * The command string is passed through as-is: callers remain responsible
     * for escaping via escapeshellcmd/escapeshellarg, exactly as before.
     *
     * @return array{output: string[], exit_code: int, timed_out: bool}
     */
    public static function run(string $command, int $timeoutSeconds = self::DEFAULT_TIMEOUT): array
    {
        $output = [];
        $exitCode = 0;

        if (self::hasTimeoutBinary() === true) {
            @exec(
                sprintf('timeout %ds %s 2>&1', $timeoutSeconds, $command),
                $output,
                $exitCode
            );

            // GNU timeout exits 124 when the time limit was hit
            return [
                'output' => $output,
                'exit_code' => $exitCode,
                'timed_out' => $exitCode === 124,
            ];
        }

        // Fallback (e.g. Windows, minimal containers): plain exec.
        // Behaviour is unchanged from pre-1.0.4 in this case.
        @exec($command . ' 2>&1', $output, $exitCode);

        return [
            'output' => $output,
            'exit_code' => $exitCode,
            'timed_out' => false,
        ];
    }

    public static function hasTimeoutBinary(): bool
    {
        if (self::$hasTimeoutBinary === null) {
            $probe = [];
            $code = 1;
            @exec('command -v timeout 2>/dev/null', $probe, $code);
            self::$hasTimeoutBinary = $code === 0 && count($probe) > 0;
        }

        return self::$hasTimeoutBinary;
    }
}
