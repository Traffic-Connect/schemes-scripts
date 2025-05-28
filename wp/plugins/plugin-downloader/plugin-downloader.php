<?php
/*
Plugin Name: Plugin Downloader and Activator
Description: Автоматически скачивает и активирует несколько плагинов с выбором одного плагина из каждой категории.
Version: 1.5
Author: Artem Khabarov (TC)
*/

// Предотвращаем прямой доступ к файлу
if (!defined('ABSPATH')) {
    exit;
}

// Загружаем основной класс
require_once plugin_dir_path(__FILE__) . 'includes/class-plugin-downloader-activator.php';

// Запускаем плагин
function run_plugin_downloader_activator() {
    $plugin = new PluginDownloaderActivator();
    $plugin->run();
}

run_plugin_downloader_activator();
