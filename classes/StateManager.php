<?php

require_once __DIR__ . '/config.php';

class StateManager
{
    /**
     * Load state from JSON file
     */
    public static function load()
    {
        if (file_exists(Config::STATE_FILE)) {
            $content = file_get_contents(Config::STATE_FILE);
            return json_decode($content, true) ?? [];
        }
        return [];
    }

    /**
     * Save state to JSON file
     */
    public static function save($state)
    {
        file_put_contents(Config::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
    }
}