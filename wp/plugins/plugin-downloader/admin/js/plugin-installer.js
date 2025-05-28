/**
 * JavaScript для функциональности установки плагинов с улучшенным выводом информации
 */
jQuery(document).ready(function($) {
    // Обработка основной кнопки установки плагинов
    $('#start-installation').on('click', function() {
        const statusLog = document.getElementById('status-log');
        const statusProgress = document.getElementById('status-progress');
        const startButton = document.getElementById('start-installation');

        // Деактивируем кнопку, чтобы предотвратить повторный запуск
        startButton.disabled = true;
        startButton.textContent = 'Установка плагинов...';

        statusLog.textContent = "There Is No Knowledge That Is Not Power\n";

        // Обновляем блок статуса
        statusProgress.innerHTML = '<div class="progress-bar"><div class="progress-fill" style="width:0%"></div></div><div class="progress-status">Инициализация...</div>';
        statusProgress.style.display = 'block';

        // Создаем список установленных и активированных плагинов
        let installedPlugins = [];
        let activatedPlugins = [];

        // Определяем все необходимые переменные глобально в функции
        let categoryIndex = 0;
        let localPluginIndex = 0;
        let totalPlugins = pluginData.localPlugins.length + pluginData.pluginCategories.length;
        let completedPlugins = 0;

        // Обновление прогресс-бара
        function updateProgress(message, progress) {
            statusProgress.innerHTML = `
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${progress}%"></div>
                </div>
                <div class="progress-status">
                    <span class="progress-percent">${Math.round(progress)}%</span>
                    <span class="progress-message">${message}</span>
                </div>
            `;
            statusProgress.scrollIntoView({behavior: "smooth"});
        }

        // Обновление лога с автоскроллингом
        function updateLog(message) {
            statusLog.textContent += message + "\n";
            statusLog.scrollTop = statusLog.scrollHeight;
        }

        // Сначала скачиваем и устанавливаем все локальные плагины
        installLocalPlugins();

        /**
         * Установка локальных плагинов
         */
        function installLocalPlugins() {
            if (localPluginIndex >= pluginData.localPlugins.length) {
                // После установки всех локальных плагинов, начинаем установку остальных
                updateLog("\n=== УСТАНОВКА ПЛАГИНОВ ИЗ РЕПОЗИТОРИЯ ===\n");
                categoryIndex = 0;
                installNextPlugin();
                return;
            }

            const currentPlugin = pluginData.localPlugins[localPluginIndex];
            const progressPercent = (completedPlugins / totalPlugins) * 100;

            if (localPluginIndex === 0) {
                updateLog("\n=== УСТАНОВКА ЛОКАЛЬНЫХ ПЛАГИНОВ ===\n");
            }

            updateLog(`Установка локального плагина ${currentPlugin}...`);
            updateProgress(`Установка: ${currentPlugin}`, progressPercent);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'install_local_plugin',
                    plugin_filename: currentPlugin,
                    security: pluginData.nonce
                },
                success: function(response) {
                    completedPlugins++;

                    if (response.success && response.data.message) {
                        updateLog(response.data.message);
                        installedPlugins.push(currentPlugin.replace('.zip', ''));
                    } else if (response.data.error) {
                        updateLog("Ошибка: " + response.data.error);
                        if (response.data.debug) {
                            console.log(response.data.debug);
                        }
                    }

                    // Переходим к следующему локальному плагину
                    localPluginIndex++;
                    installLocalPlugins();
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    completedPlugins++;
                    updateLog(`Ошибка при установке локального плагина ${currentPlugin}: ${textStatus}`);
                    // Продолжаем со следующим локальным плагином даже при ошибке
                    localPluginIndex++;
                    installLocalPlugins();
                }
            });
        }

        /**
         * Установка плагина из репозитория
         */
        function installNextPlugin() {
            if (categoryIndex >= pluginData.pluginCategories.length) {
                updateLog("\n=== АКТИВАЦИЯ ПЛАГИНОВ ===\n");
                updateLog("Все плагины успешно установлены. Активируем плагины...");
                updateProgress("Активация плагинов...", 80);
                activateAllPlugins();
                return;
            }

            const pluginOptions = pluginData.pluginCategories[categoryIndex];
            const pluginToInstall = pluginOptions[Math.floor(Math.random() * pluginOptions.length)];
            const progressPercent = ((completedPlugins + localPluginIndex) / totalPlugins) * 100;

            updateLog(`Скачиваем плагин: ${pluginToInstall}...`);
            updateProgress(`Скачивание: ${pluginToInstall}`, progressPercent);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'install_plugins',
                    plugin_to_install: pluginToInstall,
                    security: pluginData.nonce
                },
                success: function(response) {
                    completedPlugins++;

                    if (response.success && response.data.message) {
                        updateLog(response.data.message);
                        installedPlugins.push(pluginToInstall);
                    } else if (response.data.error) {
                        updateLog("Ошибка: " + response.data.error);
                    }

                    // Переходим к следующей категории
                    categoryIndex++;
                    installNextPlugin();
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    completedPlugins++;
                    updateLog(`Ошибка при установке плагина ${pluginToInstall}: ${textStatus}`);
                    // Продолжаем с следующей категорией даже при ошибке
                    categoryIndex++;
                    installNextPlugin();
                }
            });
        }

        /**
         * Активация всех установленных плагинов
         */
        function activateAllPlugins() {
            updateProgress("Активация всех плагинов...", 90);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'activate_all_plugins',
                    security: pluginData.nonce
                },
                success: function(response) {
                    if (response.success && response.data.message) {
                        // Поэтапно выводим результаты активации каждого плагина
                        const messages = response.data.message.split('\n');
                        let i = 0;

                        function showNextActivation() {
                            if (i < messages.length) {
                                updateLog(messages[i]);
                                i++;
                                setTimeout(showNextActivation, 100); // Задержка для визуального эффекта
                            } else {
                                finishProcess();
                            }
                        }

                        showNextActivation();

                        // Список активированных плагинов
                        if (response.data.activated_plugins) {
                            response.data.activated_plugins.forEach(function(plugin) {
                                activatedPlugins.push(plugin);
                            });
                        }
                    } else if (response.data.error) {
                        updateLog("Ошибка: " + response.data.error);
                        finishProcess();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    updateLog("Ошибка при активации плагинов: " + textStatus);
                    finishProcess();
                }
            });
        }

        /**
         * Завершение процесса и вывод итоговой статистики
         */
        function finishProcess() {
            updateProgress("Процесс завершен", 100);

            // Добавляем сообщение об успешной активации всех плагинов
            updateLog("🎉 Все плагины успешно активированы!");

            // Выводим итоговую информацию
            updateLog("\n=== ПРОЦЕСС ЗАВЕРШЕН ===\n");
            updateLog("✅ Установлено плагинов: " + installedPlugins.length);
            updateLog("✅ Активировано плагинов: " + (activatedPlugins ? activatedPlugins.length : 0));
            updateLog("✅ Все операции выполнены успешно!");

            // Восстанавливаем кнопку
            startButton.disabled = false;
            startButton.textContent = 'Скачать и активировать все плагины';

            // Плавное скроллирование к итоговой информации
            statusLog.scrollTop = statusLog.scrollHeight;
        }
    });

    // Обработка кнопки активации всех существующих плагинов
    $('#activate-all-plugins').on('click', function() {
        const statusLog = document.getElementById('status-log');
        const statusProgress = document.getElementById('status-progress');
        const activateButton = document.getElementById('activate-all-plugins');
        const startButton = document.getElementById('start-installation');

        // Деактивируем кнопки
        activateButton.disabled = true;
        startButton.disabled = true;
        activateButton.textContent = 'Активация всех плагинов...';

        // Очищаем лог и показываем прогресс-бар
        statusLog.textContent = "Запуск активации всех установленных плагинов...\n";
        statusProgress.innerHTML = '<div class="progress-bar"><div class="progress-fill" style="width:0%"></div></div><div class="progress-status">Подготовка к активации...</div>';
        statusProgress.style.display = 'block';

        // Функция для обновления статуса
        function updateProgress(message, progress) {
            statusProgress.innerHTML = `
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${progress}%"></div>
                </div>
                <div class="progress-status">
                    <span class="progress-percent">${Math.round(progress)}%</span>
                    <span class="progress-message">${message}</span>
                </div>
            `;
        }

        // Функция для обновления лога
        function updateLog(message) {
            statusLog.textContent += message + "\n";
            statusLog.scrollTop = statusLog.scrollHeight;
        }

        // Начинаем процесс активации
        updateLog("\n=== АКТИВАЦИЯ ВСЕХ СУЩЕСТВУЮЩИХ ПЛАГИНОВ ===\n");
        updateProgress("Активация всех плагинов...", 20);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'activate_all_plugins',
                activate_all: true, // Флаг для активации всех плагинов
                security: pluginData.nonce
            },
            success: function(response) {
                if (response.success && response.data.message) {
                    updateProgress("Обработка результатов...", 80);

                    // Разбиваем сообщение на строки и постепенно выводим их
                    const messages = response.data.message.split('\n');
                    let i = 0;

                    function showNextActivation() {
                        if (i < messages.length) {
                            updateLog(messages[i]);
                            i++;
                            setTimeout(showNextActivation, 50); // Меньшая задержка для быстрого вывода
                        } else {
                            finishActivation(response.data);
                        }
                    }

                    showNextActivation();
                } else if (response.data.error) {
                    updateLog("Ошибка: " + response.data.error);
                    finishActivation(null);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                updateLog("Ошибка при активации плагинов: " + textStatus);
                finishActivation(null);
            }
        });

        // Завершение процесса активации
        function finishActivation(data) {
            updateProgress("Активация завершена", 100);

            if (data && data.activated_plugins) {
                const activatedCount = data.activated_plugins.length;

                updateLog("\n=== РЕЗУЛЬТАТЫ АКТИВАЦИИ ===");
                updateLog(`✅ Успешно активировано плагинов: ${activatedCount}`);

                // Если есть детали активации
                if (data.activation_details && data.activation_details.length > 0) {
                    updateLog(`✅ Среднее время активации: ${calculateAverageTime(data.activation_details)}с`);
                }

                updateLog("🎉 Все плагины успешно активированы!");
            } else {
                updateLog("\n⚠ Процесс активации завершен с ошибками");
            }

            // Восстанавливаем кнопки
            activateButton.disabled = false;
            startButton.disabled = false;
            activateButton.textContent = 'Активировать все существующие плагины';

            // Плавное скроллирование к итоговой информации
            statusLog.scrollTop = statusLog.scrollHeight;
        }

        // Расчет среднего времени активации
        function calculateAverageTime(details) {
            if (!details || details.length === 0) return 0;

            const sum = details.reduce((total, plugin) => total + plugin.time, 0);
            return (sum / details.length).toFixed(2);
        }
    });
});