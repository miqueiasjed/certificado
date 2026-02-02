import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Configurar token CSRF
let token = document.head.querySelector('meta[name="csrf-token"]');

if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}

// Interceptor para tratar erro 419 (CSRF Token Mismatch) automaticamente
window.axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        if (error.response && error.response.status === 419) {
            // Tenta renovar o token CSRF
            try {
                await window.axios.get('/csrf-token');

                // Atualiza o header X-XSRF-TOKEN com o novo cookie
                const xsrfToken = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

                if (xsrfToken) {
                    // O valor do cookie pode estar encoded
                    error.config.headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfToken[1]);

                    // Remove o header X-CSRF-TOKEN antigo para garantir que o Laravel use o X-XSRF-TOKEN
                    // O Laravel verifica o X-CSRF-TOKEN primeiro, e se ele existir (mesmo inválido), usa ele.
                    delete error.config.headers['X-CSRF-TOKEN'];
                    delete error.config.headers['x-csrf-token'];
                }

                // Retenta a requisição original
                return window.axios(error.config);
            } catch (refreshError) {
                // Se falhar a renovação, deixa o erro propagar (provavelmente redirecionará para login)
                return Promise.reject(error);
            }
        }
        return Promise.reject(error);
    }
);
