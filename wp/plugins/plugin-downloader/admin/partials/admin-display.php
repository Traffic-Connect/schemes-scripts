<?php
// Предотвращаем прямой доступ к файлу
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>Скачать и активировать плагины</h1>

    <div class="plugin-actions-container" style="margin: 20px 0;">
        <button id="start-installation" class="button button-primary">Скачать и активировать все плагины</button>
        <button id="activate-all-plugins" class="button button-secondary" style="margin-left: 10px;">Активировать все существующие плагины</button>
    </div>

    <div id="plugin-installer-info" style="margin-top: 20px;">
        <!-- Блок прогресса -->
        <div id="status-progress" style="display: none; margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;">
            <div class="progress-bar" style="height: 20px; background-color: #e5e5e5; border-radius: 3px; overflow: hidden; margin-bottom: 10px;">
                <div class="progress-fill" style="width: 0%; height: 100%; background-color: #0073aa;"></div>
            </div>
            <div class="progress-status">Инициализация...</div>
        </div>

        <!-- Блок логов -->
        <div id="status-container" style="padding: 10px; border: 1px solid #ddd; background-color: #000; color: #0f0; font-family: monospace; height: 400px; overflow-y: auto;">
            <pre id="status-log">There Is No Knowledge That Is Not Power</pre>
        </div>
    </div>
</div>

<style>
    .progress-status {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .progress-percent {
        font-weight: bold;
        margin-right: 10px;
    }

    .progress-message {
        flex-grow: 1;
    }

    .progress-bar {
        height: 20px;
        background-color: #e5e5e5;
        border-radius: 3px;
        overflow: hidden;
        margin-bottom: 10px;
    }

    .progress-fill {
        height: 100%;
        background-color: #0073aa;
        transition: width 0.3s ease;
    }
</style>

<script type="text/javascript">
    // Передаем необходимые данные из PHP в JavaScript
    var pluginData = {
        pluginCategories: <?php echo json_encode($this->plugin_categories); ?>,
        localPlugins: <?php echo json_encode($this->local_plugins); ?>,
        nonce: '<?php echo wp_create_nonce('plugin_installer_nonce'); ?>'
    };
</script>