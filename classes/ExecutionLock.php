<?php

class ExecutionLock
{
    private static $lockHandle;

    public static function preventConcurrentExecution(): void
    {
        $lockFile = Config::LOCK_FILE;

        self::$lockHandle = fopen($lockFile, 'c');
        if (!self::$lockHandle) {
            Logger::error("Can't open lock file: $lockFile");
            exit(1);
        }

        if (!flock(self::$lockHandle, LOCK_EX | LOCK_NB)) {
            Logger::error("Script is already running. Exiting.");
            exit(0);
        }
    }
}
