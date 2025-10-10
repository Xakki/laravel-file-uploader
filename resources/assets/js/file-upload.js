(function (global) {
    'use strict';

    const DEFAULT_UPLOAD_OPTIONS = {
        chunkSize: 1024 * 1024,
        method: 'POST',
        headers: {},
        maxRetries: 3,
        retryDelay: 3000,
        retryDelayIncrement: 1000,
        maxRetryDelay: 20000,
        fileFieldName: 'fileChunk',
        extraFields: {},
        csrfToken: null,
        csrfTokenField: '_token',
        formDataBuilder: null,
        onStart: null,
        onChunkStart: null,
        onProgress: null,
        onRetry: null,
        onRequestCreate: null,
        onRedirect: null,
        onComplete: null,
        onError: null,
        locale: null,
    };

    function isFunction(value) {
        return typeof value === 'function';
    }

    function isPlainObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function callHook(handler, ...args) {
        if (isFunction(handler)) {
            handler(...args);
        }
    }

    function toPositiveInteger(value, fallback) {
        const parsed = Number(value);
        if (!Number.isFinite(parsed) || parsed <= 0) {
            return fallback;
        }

        return Math.floor(parsed);
    }

    function toNonNegativeNumber(value, fallback) {
        const parsed = Number(value);
        if (!Number.isFinite(parsed) || parsed < 0) {
            return fallback;
        }

        return parsed;
    }

    function mergeUploadOptions(options) {
        const userOptions = options || {};
        const merged = Object.assign({}, DEFAULT_UPLOAD_OPTIONS, userOptions);

        const defaultHeaders = isPlainObject(DEFAULT_UPLOAD_OPTIONS.headers) ? DEFAULT_UPLOAD_OPTIONS.headers : {};
        const userHeaders = isPlainObject(userOptions.headers) ? userOptions.headers : {};
        merged.headers = Object.assign({}, defaultHeaders, userHeaders);

        if (typeof userOptions.extraFields === 'function') {
            merged.extraFields = userOptions.extraFields;
        } else if (userOptions.extraFields === null) {
            merged.extraFields = null;
        } else {
            const defaultExtraFields = isPlainObject(DEFAULT_UPLOAD_OPTIONS.extraFields)
                ? DEFAULT_UPLOAD_OPTIONS.extraFields
                : {};
            const userExtraFields = isPlainObject(userOptions.extraFields) ? userOptions.extraFields : {};
            merged.extraFields = Object.assign({}, defaultExtraFields, userExtraFields);
        }

        return merged;
    }

    function createError(message, code, meta) {
        const error = new Error(message);
        error.code = code;
        if (meta) {
            Object.keys(meta).forEach((key) => {
                if (!(key in error)) {
                    error[key] = meta[key];
                }
            });
        }

        return error;
    }

    async function computeFileHash(blob) {
        if (typeof window === 'undefined' || !window.crypto || !window.crypto.subtle || !blob.arrayBuffer) {
            return '';
        }

        const buffer = await blob.arrayBuffer();
        const hashBuffer = await window.crypto.subtle.digest('SHA-256', buffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));

        return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
    }

    function normaliseUrl(url) {
        try {
            return new URL(url, window.location.href).href;
        } catch (error) {
            console.warn('Failed to normalise url', url, error);
            return url;
        }
    }

    function applyFormDataBuilder(formDataBuilder, formData, context, attempt) {
        if (isFunction(formDataBuilder)) {
            formDataBuilder(formData, Object.assign({}, context, { attempt }));
            return;
        }

        buildDefaultFormData(formData, context);
    }

    function buildDefaultFormData(formData, context) {
        if (context.csrfToken) {
            formData.append(context.csrfTokenField, context.csrfToken);
        }

        formData.append(context.fileFieldName, context.chunk, context.fileName);
        formData.append('chunkIndex', context.chunkIndex);
        formData.append('totalChunks', context.totalChunks);
        formData.append('uploadId', context.uploadId);
        formData.append('mimeType', context.mimeType);
        formData.append('fileName', context.fileName);
        formData.append('fileSize', context.fileSize);
        formData.append('fileHash', context.fileHash);
        formData.append('fileLastModified', context.fileLastModified);
        if (context.locale !== undefined && context.locale !== null) {
            formData.append('locale', context.locale);
        }

        if (context.extraFields) {
            Object.keys(context.extraFields).forEach((key) => {
                const value = context.extraFields[key];
                if (value !== undefined && value !== null) {
                    formData.append(key, value);
                }
            });
        }
    }

    function applyRequestHeaders(xhr, headers) {
        if (!isPlainObject(headers)) {
            return;
        }

        Object.keys(headers).forEach((header) => {
            const value = headers[header];
            if (value !== undefined && value !== null) {
                xhr.setRequestHeader(header, value);
            }
        });
    }

    function uploadChunkWithRetries(options) {
        const config = mergeUploadOptions(options);
        const {
            uploadUrl,
            method,
            onProgress,
            onRetry,
            onRequestCreate,
            onRedirect,
            formDataBuilder,
        } = config;

        if (!uploadUrl) {
            return Promise.reject(createError('uploadUrl is required for uploadChunkWithRetries.', 'config'));
        }

        const targetUrl = normaliseUrl(uploadUrl);
        const maxAttempts = toPositiveInteger(config.maxRetries, DEFAULT_UPLOAD_OPTIONS.maxRetries);
        const retryDelay = toNonNegativeNumber(config.retryDelay, DEFAULT_UPLOAD_OPTIONS.retryDelay);
        const retryDelayIncrement = toNonNegativeNumber(
            config.retryDelayIncrement,
            DEFAULT_UPLOAD_OPTIONS.retryDelayIncrement,
        );
        const maxRetryDelay = toNonNegativeNumber(config.maxRetryDelay, DEFAULT_UPLOAD_OPTIONS.maxRetryDelay);
        let attempt = 1;
        let currentDelay = retryDelay;

        return new Promise((resolve, reject) => {
            const attemptUpload = () => {
                const currentAttempt = attempt;

                const formData = new FormData();
                applyFormDataBuilder(formDataBuilder, formData, config, currentAttempt);

                const xhr = new XMLHttpRequest();
                xhr.open(method, targetUrl, true);

                applyRequestHeaders(xhr, config.headers);

                callHook(onRequestCreate, xhr, {
                    chunkIndex: config.chunkIndex,
                    totalChunks: config.totalChunks,
                    attempt: currentAttempt,
                    uploadId: config.uploadId,
                });

                xhr.upload.onprogress = (event) => {
                    if (event.lengthComputable) {
                        callHook(onProgress, {
                            loaded: event.loaded,
                            total: event.total,
                            chunkIndex: config.chunkIndex,
                            totalChunks: config.totalChunks,
                            attempt: currentAttempt,
                            uploadId: config.uploadId,
                        });
                    }
                };

                const handleRetry = (reason) => {
                    if (attempt >= maxAttempts) {
                        reject(reason);
                        return;
                    }

                    callHook(onRetry, {
                        chunkIndex: config.chunkIndex,
                        totalChunks: config.totalChunks,
                        attempt: currentAttempt,
                        nextAttempt: attempt + 1,
                        maxRetries: maxAttempts,
                        delay: currentDelay,
                        uploadId: config.uploadId,
                        reason,
                    });

                    const delay = currentDelay;
                    currentDelay = Math.min(currentDelay + retryDelayIncrement, maxRetryDelay);
                    attempt += 1;
                    setTimeout(attemptUpload, delay);
                };

                xhr.onload = () => {
                    const responseUrl = xhr.responseURL ? normaliseUrl(xhr.responseURL) : targetUrl;
                    const redirected = responseUrl && responseUrl !== targetUrl;

                    if (redirected) {
                        const redirectError = createError(`Request redirected to ${responseUrl}`, 'redirect', { status: xhr.status });
                        callHook(onRedirect, {
                            responseUrl,
                            targetUrl,
                            status: xhr.status,
                            chunkIndex: config.chunkIndex,
                            totalChunks: config.totalChunks,
                            attempt: currentAttempt,
                            uploadId: config.uploadId,
                            xhr,
                        });
                        reject(redirectError);
                        return;
                    }

                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve({
                            status: xhr.status,
                            response: xhr.responseText,
                            xhr,
                        });
                    } else {
                        handleRetry(createError(`Server responded with status ${xhr.status}`, 'server', {
                            status: xhr.status,
                            statusText: xhr.statusText,
                        }));
                    }
                };

                xhr.onerror = () => {
                    handleRetry(createError('Network error during chunk upload.', 'network'));
                };

                xhr.send(formData);
            };

            attemptUpload();
        });
    }

    async function sendFile(blob, options) {
        if (!(blob instanceof Blob)) {
            throw createError('First argument must be a Blob.', 'config');
        }

        const config = mergeUploadOptions(options);
        if (!config.uploadUrl) {
            throw createError('uploadUrl is required for sendFile.', 'config');
        }

        const chunkSize = toPositiveInteger(config.chunkSize, DEFAULT_UPLOAD_OPTIONS.chunkSize);
        const uploadId = config.uploadId || `upload-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
        const mimeType = config.mimeType || blob.type || 'application/octet-stream';
        const fileName = typeof config.fileName === 'function'
            ? config.fileName(blob, { uploadId, mimeType })
            : (typeof config.fileName === 'string' && config.fileName.trim()
                ? config.fileName.trim()
                : (typeof blob.name === 'string' && blob.name.trim() ? blob.name : uploadId));
        const fileSize = blob.size;
        const totalChunks = Math.max(1, Math.ceil(fileSize / chunkSize));
        const fileLastModified = blob.lastModified;

        callHook(config.onStart, {
            uploadId,
            totalChunks,
            fileName,
            mimeType,
            blob,
        });

        let finalResponse = null;

        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex += 1) {
            const start = chunkIndex * chunkSize;
            const end = Math.min(start + chunkSize, fileSize);
            const chunk = blob.slice(start, end, mimeType);
            const fileHash = await computeFileHash(chunk);
            const chunkContext = {
                uploadId,
                chunkIndex,
                totalChunks,
                fileName,
                mimeType,
                chunk,
                fileSize,
                fileHash,
                fileLastModified,
                isLastChunk: chunkIndex === totalChunks - 1,
            };
            const extraFields = typeof config.extraFields === 'function'
                ? config.extraFields(chunkContext)
                : (isPlainObject(config.extraFields) ? Object.assign({}, config.extraFields) : config.extraFields);
            if (isPlainObject(extraFields)) {
                chunkContext.extraFields = extraFields;
            }

            callHook(config.onChunkStart, chunkContext);

            const response = await uploadChunkWithRetries(Object.assign({}, config, {
                chunk,
                chunkIndex,
                totalChunks,
                uploadId,
                fileName,
                mimeType,
                fileSize,
                fileHash,
                extraFields,
                onProgress: (progress) => {
                    const chunkProgress = progress.total
                        ? Math.round((progress.loaded / progress.total) * 100)
                        : 0;
                    const overallProgress = progress.total
                        ? Math.round(((chunkIndex + (progress.loaded / progress.total)) / totalChunks) * 100)
                        : Math.round(((chunkIndex + 1) / totalChunks) * 100);
                    callHook(config.onProgress, Object.assign({}, chunkContext, {
                        chunkProgress,
                        overallProgress,
                        attempt: progress.attempt,
                    }));
                },
                onRetry: (retryInfo) => {
                    callHook(config.onRetry, Object.assign({}, chunkContext, retryInfo));
                },
                onRequestCreate: (xhr, requestContext) => {
                    callHook(config.onRequestCreate, xhr, Object.assign({}, chunkContext, requestContext));
                },
                onRedirect: (redirectInfo) => {
                    callHook(config.onRedirect, Object.assign({}, chunkContext, redirectInfo));
                },
                formDataBuilder: config.formDataBuilder ? (formData, builderContext) => {
                    config.formDataBuilder(formData, Object.assign({}, chunkContext, builderContext));
                } : null,
            }));

            if (chunkContext.isLastChunk) {
                finalResponse = response;
            }
        }

        if (isFunction(config.onComplete)) {
            try {
                config.onComplete({ uploadId, fileName, mimeType, response: finalResponse });
            } catch (error) {
                callHook(config.onError, error);
                throw error;
            }
        }

        if (config.expectJsonResponse && finalResponse && finalResponse.response) {
            try {
                const data = JSON.parse(finalResponse.response);
                finalResponse.responseData = data;
            } catch (error) {
                callHook(config.onError, error);
                throw error;
            }
        }

        return finalResponse;
    }

    global.FileUpload = {
        sendFile,
        uploadChunkWithRetries,
        buildDefaultFormData,
        createError,
        normaliseUrl,
        defaults: Object.assign({}, DEFAULT_UPLOAD_OPTIONS),
    };
}(window));
