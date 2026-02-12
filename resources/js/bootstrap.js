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

const initialToken = getMetaToken()?.getAttribute('content');
if (initialToken) {
    syncCsrfToken(initialToken);
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}

window.axios.interceptors.request.use((config) => {
    syncCsrfToken(getCookieToken() || getMetaToken()?.getAttribute('content'));
    return config;
});

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
