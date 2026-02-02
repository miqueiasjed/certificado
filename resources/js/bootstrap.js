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

                // Atualiza o token no header padrão do axios para requisições futuras
                // O Laravel atualiza o cookie XSRF-TOKEN automaticamente na resposta da rota acima
                // O axios lê esse cookie automaticamente, mas podemos forçar a atualização se necessário

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
