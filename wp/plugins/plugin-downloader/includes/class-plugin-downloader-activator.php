<?php
// Предотвращаем прямой доступ к файлу
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-plugin-installer.php';
require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-admin-page.php';

class PluginDownloaderActivator {
    private $plugin_categories;
    private $local_plugins;
    private $installer;
    private $admin_page;

    public function __construct() {
        $this->plugin_categories = [
            ['wordpress-seo', 'the-seo-framework', 'simple-seo', 'wp-seopress'],
            ['contact-form-7', 'wpforms-lite'],
            ['all-in-one-wp-security-and-firewall', 'wordfence'],
            ['redirection'],
            ['permalink-manager-lite'],
            ['htaccess-file-editor'],
            ['remove-category-url'],
            ['maintenance', 'slim-maintenance-mode', 'nifty-coming-soon-and-under-construction-page', 'simple-maintenance', 'colorlib-coming-soon-maintenance'],
        ];

        $this->local_plugins = [
            'tc-lang-replace.zip',
            'tc-relative-link-converter.zip',
            'tc-static-site.zip'
        ];

        $this->installer = new PluginInstaller($this->plugin_categories, $this->local_plugins);
        $this->admin_page = new AdminPage($this->plugin_categories, $this->local_plugins);
    }

    public function run() {
        // Регистрируем хуки админки
        add_action('admin_menu', [$this->admin_page, 'add_admin_menu']);

        // Регистрируем AJAX-эндпоинты
        add_action('wp_ajax_install_plugins', [$this->installer, 'install_plugins']);
        add_action('wp_ajax_install_local_plugin', [$this->installer, 'install_local_plugin']);
        add_action('wp_ajax_activate_all_plugins', [$this->installer, 'activate_all_plugins']);
    }
}
