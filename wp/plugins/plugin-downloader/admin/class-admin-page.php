<?php
// Предотвращаем прямой доступ к файлу
if (!defined('ABSPATH')) {
    exit;
}

class AdminPage {
    private $plugin_categories;
    private $local_plugins;

    public function __construct($plugin_categories, $local_plugins) {
        $this->plugin_categories = $plugin_categories;
        $this->local_plugins = $local_plugins;

        // Добавляем хук для регистрации стилей и скриптов
        add_action('admin_enqueue_scripts', [$this, 'register_assets']);
    }

    /**
     * Регистрация стилей и скриптов
     */
    public function register_assets($hook) {
        // Проверяем, что мы на странице нашего плагина
        if ($hook != 'toplevel_page_plugin-installer') {
            return;
        }

        // Регистрируем и подключаем CSS
        wp_enqueue_style(
            'plugin-installer-style',
            plugin_dir_url(dirname(__FILE__)) . 'admin/css/plugin-installer.css',
            array(),
            '1.0.0'
        );

        // Регистрируем и подключаем JS
        wp_enqueue_script(
            'plugin-installer-script',
            plugin_dir_url(dirname(__FILE__)) . 'admin/js/plugin-installer.js',
            ['jquery'],
            '1.0.0',
            true
        );
    }

    /**
     * Добавление пункта меню в админ-панель
     */
    public function add_admin_menu() {
        add_menu_page(
            'Скачать и активировать плагины',
            'Plugin Installer',
            'manage_options',
            'plugin-installer',
            [$this, 'render_admin_page'],
            'dashicons-download',
            100
        );
    }

    /**
     * Рендеринг страницы администратора
     */
    public function render_admin_page() {
        // Выводим HTML шаблон страницы
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/partials/admin-display.php';
    }
}