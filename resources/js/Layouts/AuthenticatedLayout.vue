<template>
  <div class="flex h-screen bg-gray-50">
    <!-- Sidebar -->
    <div class="sidebar relative flex">
      <!-- Overlay for mobile -->
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-40 bg-gray-600 bg-opacity-75 lg:hidden"
        @click="closeSidebar">
      </div>

      <!-- Sidebar panel -->
      <div
        :class="[
          'fixed inset-y-0 left-0 z-50 flex transform transition-transform duration-300 ease-in-out lg:static lg:translate-x-0',
          sidebarOpen ? 'translate-x-0' : '-translate-x-full',
          sidebarCollapsed ? 'w-16' : 'w-64'
        ]">
        <div class="flex w-full flex-col bg-green-600">
          <Sidebar
            :collapsed="sidebarCollapsed"
            @toggle-collapse="toggleSidebarCollapse" />
        </div>
      </div>
    </div>

    <!-- Main content -->
    <div class="flex flex-1 flex-col overflow-hidden">
      <!-- Top bar for mobile -->
      <div class="sticky top-0 z-10 flex h-16 shrink-0 items-center gap-x-4 border-b border-gray-200 bg-white px-4 shadow-sm sm:gap-x-6 sm:px-6 lg:px-8 lg:hidden">
        <!-- Mobile menu button -->
        <button
          type="button"
          class="-m-2.5 p-2.5 text-gray-700 lg:hidden"
          @click="openSidebar">
          <span class="sr-only">Abrir sidebar</span>
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
          </svg>
        </button>

        <!-- Mobile header -->
        <div class="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
          <div class="flex flex-1 items-center">
            <h1 class="text-lg font-semibold text-gray-900">
              Sistema de Certificados
            </h1>
          </div>
        </div>
      </div>

      <!-- Page header -->
      <div v-if="$slots.header" class="bg-white shadow-sm border-b border-gray-200">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
          <slot name="header" />
        </div>
      </div>

      <!-- Main content area -->
      <main class="flex-1 overflow-y-auto">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
          <slot />
        </div>
      </main>

      <!-- Footer -->
      <footer class="border-t border-gray-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
          <div class="text-center text-sm text-gray-500">
            &copy; {{ new Date().getFullYear() }} Sistema de Certificados. Todos os direitos reservados.
          </div>
        </div>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import Sidebar from '@/Components/Sidebar.vue';

const sidebarOpen = ref(false);
const sidebarCollapsed = ref(false);

const openSidebar = () => {
  sidebarOpen.value = true;
};

const closeSidebar = () => {
  sidebarOpen.value = false;
};

const toggleSidebarCollapse = () => {
  sidebarCollapsed.value = !sidebarCollapsed.value;
};

// Handle window resize
const handleResize = () => {
  if (window.innerWidth >= 1024) {
    sidebarOpen.value = false;
  }
};

// Handle escape key
const handleKeydown = (event) => {
  if (event.key === 'Escape' && sidebarOpen.value) {
    closeSidebar();
  }
};

onMounted(() => {
  window.addEventListener('resize', handleResize);
  document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
  window.removeEventListener('resize', handleResize);
  document.removeEventListener('keydown', handleKeydown);
});
</script>

<style scoped>
/* Estilos específicos do layout */
</style>
