(function (global, document) {
    'use strict';

    if (!global.FileUpload) {
        throw new Error('FileUpload core must be loaded before the widget.');
    }

    const CORE_DEFAULTS = global.FileUpload && global.FileUpload.defaults
        ? Object.assign({}, global.FileUpload.defaults)
        : {};

    const DEFAULT_ROUTE_PLACEHOLDER = '__ID__';

    const DEFAULT_WIDGET_OPTIONS = Object.assign({}, CORE_DEFAULTS, {
        endpointBase: '/file-upload',
        allowList: true,
        allowDelete: true,
        allowCleanup: true,
        allowDeleteAllFiles: false,
        locale: 'en',
        auth: 'csrf',
        token: null,
        styles: {},
        i18n: {},
        container: null,
        headers: {},
        routes: {},
    });

    const STRINGS = {
        en: {
            title: 'File uploader',
            toggle: '⇪',
            drop: 'Drop files here or click to browse',
            select: 'Select file',
            uploading: 'Uploading :name',
            completed: 'Upload finished',
            failed: 'Upload failed',
            files: 'Files',
            refresh: 'Refresh',
            delete: 'Delete',
            restore: 'Restore',
            close: 'Close',
            showList: 'Show files',
            hideList: 'Hide files',
            empty: 'No files yet',
            size: 'Size',
            created: 'Created',
            actions: 'Actions',
            copy: 'Copy link',
            copied: 'Link copied',
            errorCopy: 'Unable to copy',
            movedToTrash: 'File moved to trash',
        },
        ru: {
            title: 'Загрузчик файлов',
            toggle: '⇪',
            drop: 'Перетащите файлы сюда или нажмите для выбора',
            select: 'Выбрать файл',
            uploading: 'Загрузка «:name»',
            completed: 'Загрузка завершена',
            failed: 'Ошибка загрузки',
            files: 'Файлы',
            refresh: 'Обновить',
            delete: 'Удалить',
            restore: 'Восстановить',
            close: 'Закрыть',
            showList: 'Показать файлы',
            hideList: 'Скрыть файлы',
            empty: 'Пока нет файлов',
            size: 'Размер',
            created: 'Создан',
            actions: 'Действия',
            copy: 'Копировать ссылку',
            copied: 'Ссылка скопирована',
            errorCopy: 'Не удалось скопировать',
            movedToTrash: 'Файл перемещён в корзину',
        },
    };

    const CSS = `
.fu-widget{position:fixed;bottom:24px;right:24px;z-index:1050;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
.fu-toggle{width:54px;height:54px;border-radius:50%;background:#1f2937;color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;cursor:pointer;box-shadow:0 15px 30px rgba(15,23,42,.35);transition:transform .2s ease,box-shadow .2s ease;}
.fu-toggle:hover{transform:translateY(-2px);box-shadow:0 18px 32px rgba(15,23,42,.4);}
.fu-modal{position:absolute;bottom:70px;right:0;min-width:300px;max-width:90vw;background:#fff;border-radius:16px;padding:20px;box-shadow:0 25px 45px rgba(15,23,42,.25);display:none;flex-direction:column;gap:16px;}
.fu-modal.fu-open{display:flex;}
.fu-header{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.fu-title{font-size:16px;font-weight:600;color:#111827;}
.fu-close{background:none;border:none;color:#9ca3af;font-size:18px;cursor:pointer;line-height:1;}
.fu-dropzone{border:2px dashed #cbd5f5;border-radius:14px;padding:20px;text-align:center;color:#4b5563;background:linear-gradient(145deg,#f9fafb,#eef2ff);transition:border-color .2s ease,background .2s ease;cursor:pointer;}
.fu-dropzone:hover,.fu-dropzone.fu-active{border-color:#6366f1;background:#eef2ff;}
.fu-dropzone input{display:none;}
.fu-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.fu-status{font-size:13px;color:#4b5563;min-height:18px;}
.fu-progress{height:4px;background:#e5e7eb;border-radius:9999px;overflow:hidden;}
.fu-progress span{display:block;height:100%;width:0;background:linear-gradient(90deg,#4f46e5,#8b5cf6);transition:width .2s ease;}
.fu-queue{display:none;flex-direction:column;gap:10px;}
.fu-queue-item{background:#f9fafb;border-radius:12px;padding:10px 12px;box-shadow:inset 0 0 0 1px #e5e7eb;display:flex;flex-direction:column;gap:6px;transition:opacity .3s ease;}
.fu-queue-item.fu-fade-out{opacity:0;transition:opacity 3s ease;}
.fu-queue-name{font-size:13px;font-weight:500;color:#111827;word-break:break-word;cursor:pointer;}
.fu-queue-meta{display:flex;align-items:center;gap:8px;font-size:12px;}
.fu-queue-success{color:#059669;}
.fu-queue-error{color:#dc2626;}
.fu-icon-btn{border:none;background:none;color:#4f46e5;cursor:pointer;font-size:16px;display:inline-flex;align-items:center;justify-content:center;padding:0;line-height:1;}
.fu-icon-btn:hover{color:#3730a3;}
.fu-icon-btn[disabled]{color:#9ca3af;cursor:not-allowed;}
.fu-list{display:none;flex-direction:column;gap:8px;}
.fu-list.fu-open{display:flex;}
.fu-table{width:100%;border-collapse:collapse;font-size:13px;color:#374151;}
.fu-table th{font-weight:600;text-align:left;border-bottom:1px solid #e5e7eb;padding:6px 4px;}
.fu-table td{padding:6px 4px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.fu-table tr:last-child td{border-bottom:none;}
.fu-table button{border:none;background:none;color:#4f46e5;cursor:pointer;font-size:12px;padding:0 4px;}
.fu-empty{font-size:13px;color:#9ca3af;text-align:center;padding:12px 0;}
.fu-list-actions{display:flex;justify-content:space-between;align-items:center;gap:8px;}
.fu-pill{border:none;border-radius:9999px;background:#eef2ff;color:#4f46e5;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;}
.fu-pill.secondary{background:#f3f4f6;color:#374151;}
`;

    let styleInjected = false;
    const hasClipboardSupport = typeof navigator !== 'undefined'
        && navigator.clipboard
        && typeof navigator.clipboard.writeText === 'function';

    function isObject(value) {
        return value !== null && typeof value === 'object';
    }

    function injectStyles(customStyles) {
        if (!styleInjected) {
            const style = document.createElement('style');
            style.id = 'file-upload-widget-styles';
            style.textContent = CSS;
            document.head.appendChild(style);
            styleInjected = true;
        }

        if (!customStyles) {
            return;
        }

        if (typeof customStyles === 'string') {
            const style = document.createElement('style');
            style.textContent = customStyles;
            document.head.appendChild(style);
            return;
        }

        if (isObject(customStyles)) {
            Object.entries(customStyles).forEach(([selector, inlineStyles]) => {
                const elements = document.querySelectorAll(selector);
                elements.forEach((el) => Object.assign(el.style, inlineStyles));
            });
        }
    }

    function bytesToHuman(bytes) {
        if (Number.isNaN(Number(bytes))) {
            return '0 B';
        }

        if (bytes === 0) {
            return '0 B';
        }

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const value = bytes / Math.pow(1024, exponent);

        return `${value.toFixed(value < 10 && exponent > 0 ? 1 : 0)} ${units[exponent]}`;
    }

    function formatString(template, params) {
        if (!template || typeof template !== 'string') {
            return '';
        }

        if (!params) {
            return template;
        }

        return template.replace(/:([a-zA-Z0-9_]+)/g, (_, key) => (key in params ? params[key] : _));
    }

    function resolveStrings(locale, overrides) {
        const language = (locale || 'en').toLowerCase();
        const base = STRINGS[language] || STRINGS.en;
        return Object.assign({}, base, overrides && overrides[language]);
    }

    function resolveToken(options) {
        if (options.auth === 'bearer') {
            return options.token;
        }

        if (options.auth === 'csrf') {
            if (options.token) {
                return options.token;
            }

            const tokenMeta = document.querySelector('meta[name="csrf-token"]');
            if (tokenMeta) {
                return tokenMeta.getAttribute('content');
            }
        }

        return null;
    }

    function applyStyles(element, styleObject) {
        if (!element || !isObject(styleObject)) {
            return;
        }

        Object.assign(element.style, styleObject);
    }

    function emitEvent(name, detail) {
        if (typeof window.CustomEvent !== 'function') {
            return;
        }

        window.dispatchEvent(new CustomEvent(name, { detail }));
    }

    function buildRoute(template, params, placeholder) {
        if (typeof template === 'function') {
            return template(params || {});
        }

        if (!template || typeof template !== 'string') {
            return null;
        }

        const context = params && typeof params === 'object' ? params : {};
        let url = template;
        const originalUrl = url;

        Object.keys(context).forEach((key) => {
            const value = context[key];
            if (value === undefined || value === null) {
                return;
            }
            const encodedValue = encodeURIComponent(value);
            url = url.split(`{${key}}`).join(encodedValue);
            if (placeholder) {
                url = url.split(placeholder).join(encodedValue);
            }
        });

        if (url === originalUrl && Object.prototype.hasOwnProperty.call(context, 'id')) {
            const encodedId = encodeURIComponent(context.id);
            return `${url.replace(/\/+$/, '')}/${encodedId}`;
        }

        return url;
    }

    function createRequestClient(baseHeaders, authConfig) {
        const defaultHeaders = Object.assign({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        }, baseHeaders || {});

        return {
            async json(url, overrideConfig) {
                const config = Object.assign({
                    headers: Object.assign({}, defaultHeaders),
                    credentials: 'same-origin',
                }, overrideConfig || {});

                config.headers = Object.assign({}, defaultHeaders, config.headers || {});

                if (authConfig.auth === 'csrf' && authConfig.token) {
                    config.headers['X-CSRF-TOKEN'] = authConfig.token;
                }

                if (authConfig.auth === 'bearer' && authConfig.token && !config.headers.Authorization) {
                    config.headers.Authorization = `Bearer ${authConfig.token}`;
                }

                const response = await fetch(url, config);
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }

                return response.json();
            },
        };
    }

    function mergeWidgetOptions(userOptions) {
        const merged = Object.assign({}, DEFAULT_WIDGET_OPTIONS, userOptions || {});
        merged.locale = (merged.locale || DEFAULT_WIDGET_OPTIONS.locale).toLowerCase();
        merged.routes = Object.assign({}, DEFAULT_WIDGET_OPTIONS.routes, merged.routes || {});
        merged.headers = Object.assign({}, DEFAULT_WIDGET_OPTIONS.headers, merged.headers || {});
        return merged;
    }

    function resolveContainer(containerOption) {
        if (!containerOption) {
            return null;
        }

        if (typeof containerOption === 'string') {
            return document.querySelector(containerOption);
        }

        return containerOption;
    }

    function renderWidget(userOptions) {
        const options = mergeWidgetOptions(userOptions);
        injectStyles(options.styles && options.styles.raw ? options.styles.raw : null);

        const container = resolveContainer(options.container);
        const root = container || document.createElement('div');
        root.className = 'fu-widget';

        const strings = resolveStrings(options.locale, options.i18n);

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'fu-toggle';
        toggle.textContent = strings.toggle;

        const modal = document.createElement('div');
        modal.className = 'fu-modal';

        const header = document.createElement('div');
        header.className = 'fu-header';

        const title = document.createElement('span');
        title.className = 'fu-title';
        title.textContent = strings.title;

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'fu-close';
        close.innerHTML = '&times;';

        header.appendChild(title);
        header.appendChild(close);

        const dropzone = document.createElement('label');
        dropzone.className = 'fu-dropzone';
        dropzone.textContent = strings.drop;

        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = true;
        dropzone.appendChild(input);

        const status = document.createElement('div');
        status.className = 'fu-status';

        const queueContainer = document.createElement('div');
        queueContainer.className = 'fu-queue';

        const listActions = document.createElement('div');
        listActions.className = 'fu-list-actions';

        const toggleList = document.createElement('button');
        toggleList.type = 'button';
        toggleList.className = 'fu-pill secondary';
        toggleList.textContent = strings.showList;

        const refreshButton = document.createElement('button');
        refreshButton.type = 'button';
        refreshButton.className = 'fu-pill';
        refreshButton.textContent = strings.refresh;

        listActions.appendChild(toggleList);
        listActions.appendChild(refreshButton);

        const listContainer = document.createElement('div');
        listContainer.className = 'fu-list';

        const table = document.createElement('table');
        table.className = 'fu-table';
        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        ['files', 'size', 'created', ''].forEach((key) => {
            const th = document.createElement('th');
            if (key === '') th.textContent = '';
            else th.textContent = strings[key];
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        table.appendChild(tbody);

        const empty = document.createElement('div');
        empty.className = 'fu-empty';
        empty.textContent = strings.empty;

        listContainer.appendChild(table);
        listContainer.appendChild(empty);

        modal.appendChild(header);
        modal.appendChild(dropzone);
        modal.appendChild(status);
        modal.appendChild(queueContainer);
        if (options.allowList) {
            modal.appendChild(listActions);
            modal.appendChild(listContainer);
        }

        root.appendChild(modal);
        root.appendChild(toggle);

        if (!container) {
            document.body.appendChild(root);
        }

        applyStyles(toggle, options.styles && options.styles.toggle);
        applyStyles(modal, options.styles && options.styles.modal);
        applyStyles(dropzone, options.styles && options.styles.dropzone);

        let isOpen = false;
        let listOpen = false;
        let queue = [];
        let uploading = false;
        const fileEntries = new Map();
        const headers = Object.assign({}, options.headers || {});
        const token = resolveToken(options);
        if (options.auth === 'bearer' && token) {
            headers.Authorization = headers.Authorization || `Bearer ${token}`;
        }

        const requestClient = createRequestClient(headers, { auth: options.auth, token });

        const routePlaceholder = typeof options.routePlaceholder === 'string' && options.routePlaceholder.length
            ? options.routePlaceholder
            : DEFAULT_ROUTE_PLACEHOLDER;
        const routes = Object.assign({}, options.routes || {});

        async function copyToClipboard(value) {
            if (!hasClipboardSupport || !value) {
                return false;
            }

            try {
                await navigator.clipboard.writeText(value);
                return true;
            } catch (error) {
                console.warn(error);
                return false;
            }
        }

        function openUrlInNewTab(url) {
            if (!url) {
                return false;
            }

            try {
                const opened = window.open(url, '_blank', 'noopener');
                return Boolean(opened);
            } catch (error) {
                console.warn(error);
                return false;
            }
        }

        function updateQueueVisibility() {
            queueContainer.style.display = queueContainer.childElementCount ? 'flex' : 'none';
        }

        function createQueueEntry(file) {
            const item = document.createElement('div');
            item.className = 'fu-queue-item';

            const name = document.createElement('div');
            name.className = 'fu-queue-name';
            name.textContent = file.name;

            const meta = document.createElement('div');
            meta.className = 'fu-queue-meta';

            const progress = document.createElement('div');
            progress.className = 'fu-progress';
            const bar = document.createElement('span');
            progress.appendChild(bar);

            meta.appendChild(progress);
            item.appendChild(name);
            item.appendChild(meta);
            queueContainer.appendChild(item);

            const entry = { item, name, meta, progress, bar, file };
            fileEntries.set(file, entry);
            updateQueueVisibility();

            return entry;
        }

        function getQueueEntry(file) {
            if (fileEntries.has(file)) {
                return fileEntries.get(file);
            }

            return createQueueEntry(file);
        }

        function setEntryProgress(entry, value) {
            const clamped = Math.max(0, Math.min(100, value || 0));
            entry.bar.style.width = `${clamped}%`;
        }

        function clearEntryMeta(entry) {
            entry.meta.innerHTML = '';
        }

        function removeEntry(entry) {
            entry.item.remove();
            fileEntries.delete(entry.file);
            updateQueueVisibility();
        }

        function markEntrySuccess(entry, message, url) {
            clearEntryMeta(entry);

            const text = document.createElement('span');
            text.className = 'fu-queue-success';
            text.textContent = message || '';
            entry.meta.appendChild(text);

            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'fu-icon-btn';
            copyBtn.textContent = '📋';
            copyBtn.title = strings.copy;
            copyBtn.setAttribute('aria-label', strings.copy);

            if (url) {
                copyBtn.addEventListener('click', async () => {
                    const copied = await copyToClipboard(url);
                    if (copied) {
                        setStatus(strings.copied);
                        entry.item.classList.add('fu-fade-out');
                        setTimeout(() => removeEntry(entry), 3000);
                        return;
                    }

                    if (!openUrlInNewTab(url)) {
                        setStatus(strings.errorCopy, 'error');
                        return;
                    }

                    setStatus(strings.errorCopy, 'error');
                });
            } else {
                copyBtn.disabled = true;
                copyBtn.addEventListener('click', () => {
                    setStatus(strings.errorCopy, 'error');
                });
            }
            entry.meta.appendChild(copyBtn);
            entry.name.addEventListener('click', async () => {
                entry.item.remove();
            });
            setTimeout(function(){entry.meta.remove()}, 10);
        }

        /*
            entry{
                bar: <span style="width: 100%;">
                file: File { name: "photo_2025-04-07_20-28-57.jpg", lastModified: 1744046941410, size: 147719, … }
                item: <div class="fu-queue-item">
                meta: <div class="fu-queue-meta">
                name: <div class="fu-queue-name">
                progress: <div class="fu-progress">
            }
         */
        function markEntryError(entry, message) {
            clearEntryMeta(entry);

            const text = document.createElement('span');
            text.className = 'fu-queue-error';
            text.textContent = message || '';
            entry.meta.appendChild(text);
            entry.name.addEventListener('click', async () => {
                entry.item.remove();
            });
        }

        function setStatus(message, type) {
            status.textContent = message || '';
            status.style.color = type === 'error' ? '#dc2626' : '#4b5563';
        }

        function toggleModal(force) {
            isOpen = typeof force === 'boolean' ? force : !isOpen;
            modal.classList.toggle('fu-open', isOpen);
        }

        function toggleFileList(force) {
            if (!options.allowList) {
                return;
            }
            listOpen = typeof force === 'boolean' ? force : !listOpen;
            listContainer.classList.toggle('fu-open', listOpen);
            toggleList.textContent = listOpen ? strings.hideList : strings.showList;
        }

        function updateTable(files) {
            tbody.innerHTML = '';
            if (!files || !files.length) {
                empty.style.display = 'block';
                table.style.display = 'none';
                return;
            }
            empty.style.display = 'none';
            table.style.display = 'table';
            files.forEach((file) => {
                const row = document.createElement('tr');

                const nameCell = document.createElement('td');
                if (file.url) {
                    const nodeFileUrl = document.createElement('a');
                    nodeFileUrl.href = file.url;
                    nodeFileUrl.textContent = file.name;
                    nodeFileUrl.title = strings.copy;
                    nodeFileUrl.target = '_blank';
                    nodeFileUrl.addEventListener('click', async () => {
                        const copied = await copyToClipboard(file.url);
                        if (copied) {
                            setStatus(strings.copied);
                            return false;
                        }
                    });
                    nameCell.appendChild(nodeFileUrl);
                }
                else {
                    nameCell.textContent = file.name;
                }
                row.appendChild(nameCell);

                const sizeCell = document.createElement('td');
                sizeCell.textContent = bytesToHuman(file.size);
                row.appendChild(sizeCell);

                const createdCell = document.createElement('td');
                createdCell.textContent = file.createdAt ? new Date(file.createdAt).toLocaleString() : '';
                row.appendChild(createdCell);

                const actionsCell = document.createElement('td');

                if (options.allowDelete && (options.allowDeleteAllFiles || file.own)) {
                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'fu-icon-btn';
                    deleteBtn.textContent = '🗑️';
                    deleteBtn.title = strings.delete;
                    deleteBtn.setAttribute('aria-label', strings.delete);
                    deleteBtn.addEventListener('click', () => deleteFile(file.id));
                    actionsCell.appendChild(deleteBtn);
                }

                if (options.allowCleanup && file.deletedAt) {
                    const restoreBtn = document.createElement('button');
                    restoreBtn.type = 'button';
                    restoreBtn.textContent = strings.restore;
                    restoreBtn.addEventListener('click', () => restoreFile(file.id));
                    actionsCell.appendChild(restoreBtn);
                }

                row.appendChild(actionsCell);
                tbody.appendChild(row);
            });
        }

        async function fetchFiles() {
            if (!options.allowList) {
                return;
            }

            try {
                const url = buildRoute(routes.list || `${options.endpointBase}/files`, {}, routePlaceholder);
                const response = await requestClient.json(url);
                const files = response && response.data && Array.isArray(response.data.files)
                    ? response.data.files
                    : [];
                updateTable(files);
                setStatus('');
            } catch (error) {
                console.warn(error);
                setStatus(strings.failed, 'error');
            }
        }

        async function deleteFile(id) {
            try {
                if (!confirm(strings.delete)) return;
                const url = buildRoute(routes.delete || `${options.endpointBase}/files/${routePlaceholder}`, { id }, routePlaceholder);
                await requestClient.json(url, { method: 'DELETE' });
                setStatus(strings.movedToTrash || strings.delete);
                await fetchFiles();
                emitEvent('file-uploader:deleted', { id });
            } catch (error) {
                console.warn(error);
                setStatus(strings.failed, 'error');
            }
        }

        async function restoreFile(id) {
            try {
                const url = buildRoute(routes.restore || `${options.endpointBase}/files/${routePlaceholder}/restore`, { id }, routePlaceholder);
                await requestClient.json(url, { method: 'POST' });
                await fetchFiles();
            } catch (error) {
                console.warn(error);
                setStatus(strings.failed, 'error');
            }
        }

        async function processQueue() {
            if (uploading) {
                return;
            }

            if (queue.length === 0) {
                updateQueueVisibility();
                return;
            }

            const file = queue.shift();
            if (!file) {
                processQueue();
                return;
            }

            const entry = getQueueEntry(file);
            uploading = true;

            const uploadUrl = buildRoute(routes.upload || `${options.endpointBase}/chunks`, {}, routePlaceholder);
            const uploadOptions = {
                uploadUrl,
                headers,
                chunkSize: options.chunkSize,
                method: options.method,
                csrfToken: options.auth === 'csrf' ? token : null,
                csrfTokenField: options.csrfTokenField || '_token',
                locale: options.locale,
                expectJsonResponse: true,
                fileName: file.name,
                extraFields: {},
                onStart: () => {
                    setStatus(formatString(strings.uploading, { name: file.name }));
                    setEntryProgress(entry, 0);
                },
                onProgress: ({ overallProgress }) => {
                    setEntryProgress(entry, overallProgress);
                },
                onComplete: async () => {
                    setEntryProgress(entry, 100);
                    setStatus(strings.completed);
                    if (options.allowList) {
                        await fetchFiles();
                    }
                },
                onRetry: ({ nextAttempt }) => {
                    setStatus(`${formatString(strings.uploading, { name: file.name })} (retry ${nextAttempt})`);
                },
                formDataBuilder: (formData, ctx) => {
                    global.FileUpload.buildDefaultFormData(formData, Object.assign({}, ctx, {
                        csrfToken: options.auth === 'csrf' ? token : null,
                    }));
                },
            };

            if (options.auth === 'bearer' && token) {
                uploadOptions.headers = Object.assign({}, headers, {
                    Authorization: `Bearer ${token}`,
                });
            }

            try {
                const result = await global.FileUpload.sendFile(file, uploadOptions);
                const responseData = result && result.responseData && result.responseData.data;
                const metadata = responseData && responseData.metadata ? responseData.metadata : {};
                const fileUrl = metadata && metadata.url ? metadata.url : null;

                if (responseData && responseData.completed) {
                    markEntrySuccess(entry, `${strings.completed}: ${file.name}`, fileUrl);
                    emitEvent('file-uploader:success', { file: file.name, metadata });
                } else {
                    setStatus(strings.failed, 'error');
                    markEntryError(entry, `${strings.failed}: ${file.name}`);
                    emitEvent('file-uploader:error', { file: file.name, metadata });
                }
            } catch (error) {
                console.warn(error);
                setStatus(strings.failed, 'error');
                const errorMessage = `${strings.failed}: ${file.name}`;
                markEntryError(entry, errorMessage);
                emitEvent('file-uploader:error', { file: file.name, error });
            } finally {
                uploading = false;
                processQueue();
            }
        }

        function enqueue(files) {
            const incoming = Array.from(files || []);
            incoming.forEach((file) => {
                getQueueEntry(file);
            });
            if (!incoming.length) {
                return;
            }
            queue.push(...incoming);
            processQueue();
        }

        toggle.addEventListener('click', () => {
            toggleModal();
        });

        close.addEventListener('click', () => toggleModal(false));

        input.addEventListener('change', (event) => {
            enqueue(event.target.files);
            event.target.value = '';
        });

        dropzone.addEventListener('dragover', (event) => {
            event.preventDefault();
            dropzone.classList.add('fu-active');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('fu-active');
        });

        dropzone.addEventListener('drop', (event) => {
            event.preventDefault();
            dropzone.classList.remove('fu-active');
            if (event.dataTransfer && event.dataTransfer.files) {
                enqueue(event.dataTransfer.files);
            }
        });

        dropzone.addEventListener('click', () => input.click());

        if (options.allowList) {
            toggleList.addEventListener('click', () => {
                toggleFileList();
                if (listOpen) {
                    fetchFiles();
                }
            });

            refreshButton.addEventListener('click', fetchFiles);
        }

        return {
            root,
            destroy() {
                root.remove();
            },
            refresh: fetchFiles,
        };
    }

    const FileUploadWidget = {
        init(userOptions) {
            return renderWidget(userOptions);
        },
    };

    global.FileUploadWidget = FileUploadWidget;

    if (global.FileUploadConfig) {
        global.FileUploadWidget.init(global.FileUploadConfig);
    }
}(window, document));
