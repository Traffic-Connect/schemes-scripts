<?php
// Предотвращаем прямой доступ к файлу
if (!defined('ABSPATH')) {
    exit;
}

class PluginInstaller {
    private $plugin_categories;
    private $local_plugins;

    public function __construct($plugin_categories, $local_plugins) {
        $this->plugin_categories = $plugin_categories;
        $this->local_plugins = $local_plugins;
    }

    /**
     * Установка локального плагина из ZIP-файла
     */
    public function install_local_plugin() {
        check_ajax_referer('plugin_installer_nonce', 'security');

        // Получаем имя файла из запроса
        $plugin_filename = isset($_POST['plugin_filename']) ? sanitize_text_field($_POST['plugin_filename']) : 'tc-lang-replace.zip';

        $this->load_filesystem_dependencies();

        $creds = request_filesystem_credentials('', '', false, false, null);
        if (!WP_Filesystem($creds)) {
            wp_send_json_error([
                'error' => "Ошибка: невозможно получить доступ к файловой системе."
            ]);
            return;
        }

        global $wp_filesystem;

        // Расширенный поиск локального плагина
        $debug_info = "Debug: Ищем файл {$plugin_filename}\n";
        $local_plugin_path = $this->find_local_plugin($plugin_filename, $debug_info);

        if (!$local_plugin_path) {
            wp_send_json_error([
                'error' => "Файл {$plugin_filename} не найден ни в одной из директорий.",
                'debug' => $debug_info
            ]);
            return;
        }

        $debug_info .= "Найден файл по пути: " . $local_plugin_path . "\n";
        $debug_info .= "Размер файла: " . filesize($local_plugin_path) . " байт\n";

        // Распаковываем файл
        $result = unzip_file($local_plugin_path, WP_PLUGIN_DIR);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'error' => "Ошибка распаковки {$plugin_filename}: " . $result->get_error_message(),
                'debug' => $debug_info
            ]);
            return;
        }

        // Извлекаем имя плагина из архива
        $plugin_name = str_replace('.zip', '', $plugin_filename);

        wp_send_json_success([
            'message' => "✅ Локальный плагин {$plugin_filename} успешно установлен.",
            'plugin_name' => $plugin_name,
            'debug' => $debug_info
        ]);
    }

    /**
     * Установка плагина из репозитория WordPress.org
     */
    public function install_plugins() {
        check_ajax_referer('plugin_installer_nonce', 'security');

        $plugin_to_install = sanitize_text_field($_POST['plugin_to_install']);
        $slug = trim($plugin_to_install, '/');
        $plugin_zip_url = "https://downloads.wordpress.org/plugin/{$slug}.latest-stable.zip";

        $this->load_filesystem_dependencies();

        $creds = request_filesystem_credentials('', '', false, false, null);
        if (!WP_Filesystem($creds)) {
            wp_send_json_error(['error' => "Ошибка: невозможно получить доступ к файловой системе."]);
            return;
        }

        global $wp_filesystem;

        // Логируем начало загрузки
        $start_time = microtime(true);

        $tmp_file = download_url($plugin_zip_url);

        // Вычисляем время загрузки
        $download_time = round(microtime(true) - $start_time, 2);

        if (is_wp_error($tmp_file)) {
            wp_send_json_error(['error' => "Ошибка загрузки {$slug}: " . $tmp_file->get_error_message()]);
            return;
        }

        // Получаем размер файла для отчета
        $file_size = size_format(filesize($tmp_file));

        // Начинаем распаковку
        $unzip_start = microtime(true);
        $result = unzip_file($tmp_file, WP_PLUGIN_DIR);
        $wp_filesystem->delete($tmp_file);

        $unzip_time = round(microtime(true) - $unzip_start, 2);

        if (is_wp_error($result)) {
            wp_send_json_error(['error' => "Ошибка распаковки {$slug}: " . $result->get_error_message()]);
            return;
        }

        // Сообщение об успешной установке с подробностями
        $message = "✅ Плагин {$slug} успешно установлен.";
        $message .= " [Размер: {$file_size}, Загрузка: {$download_time}с, Распаковка: {$unzip_time}с]";

        wp_send_json_success([
            'message' => $message,
            'plugin_name' => $slug,
            'file_size' => $file_size,
            'download_time' => $download_time,
            'unzip_time' => $unzip_time
        ]);
    }

    /**
     * Активация всех установленных плагинов
     *
     * Если активируются все плагины после установки, то проверяем только те,
     * что были установлены в этой сессии.
     * Если это специальная функция активации всех (с флагом activate_all),
     * то активируем абсолютно все установленные плагины.
     */
    public function activate_all_plugins() {
        check_ajax_referer('plugin_installer_nonce', 'security');

        $this->load_plugins_dependencies();

        // Проверяем, активируются все плагины или только установленные
        $activate_all = isset($_POST['activate_all']) && $_POST['activate_all'] === 'true';

        $all_plugins = get_plugins();
        $log = $activate_all
            ? "=== АКТИВАЦИЯ ВСЕХ УСТАНОВЛЕННЫХ ПЛАГИНОВ ===\n\n"
            : "Начинаем активацию всех плагинов...\n";

        $activated_plugins = [];
        $activation_details = [];
        $total_plugins = count($all_plugins);
        $current = 0;

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $current++;
            $percent_complete = round(($current / $total_plugins) * 100);

            // Пропускаем уже активированные плагины
            if (is_plugin_active($plugin_file)) {
                $log .= "✓ Плагин \"{$plugin_data['Name']}\" уже активирован. [{$current}/{$total_plugins}, {$percent_complete}%]\n";
                continue;
            }

            // Добавляем сообщение о начале активации текущего плагина
            $log .= "⏳ Активируем \"{$plugin_data['Name']}\"... [{$current}/{$total_plugins}, {$percent_complete}%] ";

            // Замеряем время активации
            $activation_start = microtime(true);
            $activation_result = activate_plugin($plugin_file);
            $activation_time = round(microtime(true) - $activation_start, 2);

            if (is_wp_error($activation_result)) {
                $log .= "⚠ ОШИБКА: " . $activation_result->get_error_message() . "\n";
            } else {
                $log .= "УСПЕШНО! [{$activation_time}с]\n";
                $activated_plugins[] = $plugin_data['Name'];

                // Сохраняем детали для отчета
                $activation_details[] = [
                    'name' => $plugin_data['Name'],
                    'time' => $activation_time,
                    'version' => $plugin_data['Version']
                ];
            }
        }

        // Добавляем итоговую статистику
        if (count($activated_plugins) > 0) {
            $log .= "\nСтатистика активации:\n";
            $log .= "- Всего активировано: " . count($activated_plugins) . " плагинов из " . count($all_plugins) . "\n";

            // Определяем самый быстрый и самый медленный плагин
            usort($activation_details, function($a, $b) {
                return $a['time'] <=> $b['time'];
            });

            if (!empty($activation_details)) {
                $fastest = $activation_details[0];
                $slowest = $activation_details[count($activation_details) - 1];

                $log .= "- Быстрее всего: \"{$fastest['name']}\" ({$fastest['time']}с)\n";
                $log .= "- Медленнее всего: \"{$slowest['name']}\" ({$slowest['time']}с)\n";

                // Считаем среднее время активации
                $total_time = array_sum(array_column($activation_details, 'time'));
                $average_time = round($total_time / count($activation_details), 2);
                $log .= "- Среднее время активации: {$average_time}с\n";
            }
        }

        wp_send_json_success([
            'message' => $log,
            'activated_plugins' => $activated_plugins,
            'activation_details' => $activation_details,
            'total_plugins' => count($all_plugins),
            'activated_count' => count($activated_plugins)
        ]);
    }

    /**
     * Поиск локального плагина в разных директориях
     */
    private function find_local_plugin($plugin_filename, &$debug_info) {
        // Вариант 1: Прямой путь к директории плагина
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $local_plugin_path = $plugin_dir . $plugin_filename;
        $debug_info .= "Проверяем путь 1: " . $local_plugin_path . "\n";

        if (file_exists($local_plugin_path)) {
            return $local_plugin_path;
        }

        // Вариант 2: WP_PLUGIN_DIR + имя плагина
        $plugin_basename = basename(dirname(dirname(__FILE__)));
        $local_plugin_path = WP_PLUGIN_DIR . '/' . $plugin_basename . '/' . $plugin_filename;
        $debug_info .= "Проверяем путь 2: " . $local_plugin_path . "\n";

        if (file_exists($local_plugin_path)) {
            return $local_plugin_path;
        }

        // Вариант 3: Абсолютный путь к плагину
        $plugin_real_path = plugin_dir_path(dirname(__FILE__));
        $local_plugin_path = $plugin_real_path . $plugin_filename;
        $debug_info .= "Проверяем путь 3: " . $local_plugin_path . "\n";

        if (file_exists($local_plugin_path)) {
            return $local_plugin_path;
        }

        // Вариант 4: Поиск в корне плагинов
        $local_plugin_path = WP_PLUGIN_DIR . '/' . $plugin_filename;
        $debug_info .= "Проверяем путь 4: " . $local_plugin_path . "\n";

        if (file_exists($local_plugin_path)) {
            return $local_plugin_path;
        }

        // Если не нашли файл, добавляем информацию о содержимом директории
        $debug_info .= "\nСодержимое текущей директории плагина ($plugin_dir):\n";
        if ($handle = opendir($plugin_dir)) {
            while (false !== ($entry = readdir($handle))) {
                $debug_info .= "- " . $entry . "\n";
            }
            closedir($handle);
        }

        return false;
    }

    /**
     * Загрузка необходимых зависимостей для файловой системы
     */
    private function load_filesystem_dependencies() {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('size_format')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
    }

    /**
     * Загрузка зависимостей для работы с плагинами
     */
    private function load_plugins_dependencies() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
    }
}