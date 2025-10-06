import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = 'Sistema de Certificados';

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
  setup({ el, App, props, plugin }) {
    const app = createApp({ render: () => h(App, props) });
    app.use(plugin);
    app.use(ZiggyVue);

    // Diretiva personalizada para máscara de moeda brasileira
    app.directive('currency', {
      mounted(el, binding) {
        const formatCurrency = (value) => {
          // Remove tudo exceto números
          let numbers = value.replace(/\D/g, '');

          if (numbers === '') return '';

          // Converte para centavos
          let amount = parseFloat(numbers) / 100;

          // Formata como moeda brasileira
          return amount.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
          });
        };

        el.addEventListener('input', function(e) {
          const formatted = formatCurrency(e.target.value);
          e.target.value = formatted;

          // Dispara evento personalizado para notificar mudança
          e.target.dispatchEvent(new Event('formatted-input', { bubbles: true }));
        });

        el.addEventListener('blur', function(e) {
          if (e.target.value === '') {
            e.target.value = '0,00';
          }
        });
      }
    });

    app.mount(el);
  },
  progress: {
    color: '#059669', // Cor verde do tema
  },
});
