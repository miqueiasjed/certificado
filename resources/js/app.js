import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';


// Helper global para rotas do Laravel (temporário até implementar Ziggy)
window.route = function(name, params = {}) {
    const routes = {
        'home': '/',
        'login': '/login',
        'login.post': '/login',
        'logout': '/logout',
        'clients.index': '/clients',
        'clients.create': '/clients/create',
        'clients.show': (id) => `/clients/${id}`,
        'clients.edit': (id) => `/clients/${id}/edit`,
        'clients.destroy': (id) => `/clients/${id}`,
        'products.index': '/products',
        'service-orders.index': '/service-orders',
        'certificates.index': '/certificates',
    };

    if (typeof routes[name] === 'function') {
        return routes[name](params);
    }

    return routes[name] || '/';
};

const appName = 'Sistema de Certificados';

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
  setup({ el, App, props, plugin }) {
    const app = createApp({ render: () => h(App, props) });
    app.use(plugin);
    app.mount(el);
  },
  progress: {
    color: '#059669', // Cor verde do tema
  },
});
