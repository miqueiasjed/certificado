import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
window.axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

const getMetaToken = () => document.head.querySelector('meta[name="csrf-token"]');

const getCookieToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
};

const syncCsrfToken = (token) => {
    if (!token) {
        return;
    }

    const meta = getMetaToken();
    if (meta) {
        meta.setAttribute('content', token);
    }

    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
};

const isReadOnlyMethod = (method) => ['GET', 'HEAD', 'OPTIONS'].includes((method || 'GET').toUpperCase());
const getRequestMethod = (input, init) => (init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();

let refreshingTokenPromise = null;

const refreshCsrfToken = async () => {
    if (!refreshingTokenPromise) {
        refreshingTokenPromise = window.axios
            .get('/csrf-token')
            .then((response) => {
                const token = response?.data?.csrf_token || getCookieToken();
                syncCsrfToken(token);
                return token;
            })
            .finally(() => {
                refreshingTokenPromise = null;
            });
    }

    return refreshingTokenPromise;
};

const initialToken = getMetaToken()?.getAttribute('content');
if (initialToken) {
    syncCsrfToken(initialToken);
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}

const nativeFetch = window.fetch.bind(window);
window.fetch = async (input, init = {}) => {
    const requestUrl = typeof input === 'string' || input instanceof URL
        ? String(input)
        : input?.url;

    const url = requestUrl ? new URL(requestUrl, window.location.origin) : null;
    const isSameOrigin = !!(url && url.origin === window.location.origin);
    const requestMethod = getRequestMethod(input, init);
    const isCsrfRefreshRequest = url?.pathname === '/csrf-token';
    const requestFactory = () => (input instanceof Request ? input.clone() : input);

    if (isSameOrigin) {
        const token = getCookieToken() || getMetaToken()?.getAttribute('content');
        syncCsrfToken(token);

        if (!isReadOnlyMethod(requestMethod) && token) {
            const headers = new Headers(
                init.headers || (input instanceof Request ? input.headers : undefined)
            );

            headers.set('X-CSRF-TOKEN', token);
            init = { ...init, headers };
        }

        if (init.credentials === undefined) {
            init = { ...init, credentials: 'same-origin' };
        }
    }

    let response = await nativeFetch(requestFactory(), init);

    if (
        isSameOrigin
        && !isReadOnlyMethod(requestMethod)
        && response.status === 419
        && !isCsrfRefreshRequest
    ) {
        const refreshedToken = await refreshCsrfToken();

        if (refreshedToken) {
            const retryHeaders = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
            retryHeaders.set('X-CSRF-TOKEN', refreshedToken);

            const retryInit = {
                ...init,
                headers: retryHeaders,
                credentials: init.credentials ?? 'same-origin',
            };

            response = await nativeFetch(requestFactory(), retryInit);
        }
    }

    return response;
};

window.axios.interceptors.request.use((config) => {
    syncCsrfToken(getCookieToken() || getMetaToken()?.getAttribute('content'));
    return config;
});

window.axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const originalRequest = error?.config;
        const isCsrfRefreshRequest = originalRequest?.url?.includes('/csrf-token');

        if (error?.response?.status === 419 && originalRequest && !originalRequest._csrfRetried && !isCsrfRefreshRequest) {
            originalRequest._csrfRetried = true;

            try {
                const refreshedToken = await refreshCsrfToken();

                if (refreshedToken) {
                    originalRequest.headers = {
                        ...(originalRequest.headers || {}),
                        'X-CSRF-TOKEN': refreshedToken,
                    };
                }

                return window.axios(originalRequest);
            } catch {
                return Promise.reject(error);
            }
        }

        return Promise.reject(error);
    }
);
