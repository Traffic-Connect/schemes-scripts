<?php

require_once 'config.php';

class Logger
{
    /**
     * Log message to file and output
     */
    public static function log($message)
    {
        $date = date('Y-m-d H:i:s');
        $logMessage = "[$date] $message" . PHP_EOL;
        file_put_contents(Config::LOG_FILE, $logMessage, FILE_APPEND);
        echo $logMessage;
    }

    /**
     * Handle error and exit
     */
    public static function error($message)
    {
        self::log("ERROR: $message");
        exit(1);
    }
}