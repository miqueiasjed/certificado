<template>
  <div class="flex h-full flex-col">
    <!-- Logo -->
    <div class="flex h-16 shrink-0 items-center border-b border-green-700 px-4">
      <Link href="/" class="flex items-center">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-green-500">
          <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
        </div>
        <span v-if="!collapsed" class="ml-3 text-lg font-semibold text-white">
          Sistema
        </span>
      </Link>
    </div>

    <!-- Navigation -->
    <nav class="flex flex-1 flex-col px-4 py-4">
      <ul role="list" class="flex flex-1 flex-col gap-y-1">
        <!-- Main Navigation -->
                <li v-for="item in navigation" :key="item.name">
          <Link
            :href="item.href"
            :class="[
              item.current
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <!-- Dashboard Icon -->
            <svg v-if="item.icon === 'DashboardIcon'"
                 :class="[
                   item.current ? 'text-white' : 'text-green-300 group-hover:text-white',
                   'h-5 w-5 shrink-0 transition-colors'
                 ]"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
            </svg>

            <!-- Users Icon -->
            <svg v-else-if="item.icon === 'UsersIcon'"
                 :class="[
                   item.current ? 'text-white' : 'text-green-300 group-hover:text-white',
                   'h-5 w-5 shrink-0 transition-colors'
                 ]"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0zM7 10a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
            </svg>

            <!-- Cube Icon -->
            <svg v-else-if="item.icon === 'CubeIcon'"
                 :class="[
                   item.current ? 'text-white' : 'text-green-300 group-hover:text-white',
                   'h-5 w-5 shrink-0 transition-colors'
                 ]"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
            </svg>

            <!-- Document Text Icon -->
            <svg v-else-if="item.icon === 'DocumentTextIcon'"
                 :class="[
                   item.current ? 'text-white' : 'text-green-300 group-hover:text-white',
                   'h-5 w-5 shrink-0 transition-colors'
                 ]"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>

            <!-- Badge Check Icon -->
            <svg v-else-if="item.icon === 'BadgeCheckIcon'"
                 :class="[
                   item.current ? 'text-white' : 'text-green-300 group-hover:text-white',
                   'h-5 w-5 shrink-0 transition-colors'
                 ]"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>

            <span v-if="!collapsed">{{ item.name }}</span>
          </Link>
        </li>

        <!-- Spacer -->
        <li class="mt-auto">
          <div class="border-t border-green-700 pt-4">
            <!-- User Menu -->
            <div class="relative">
              <button
                @click="toggleUserMenu"
                :class="[
                  'group flex w-full items-center gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 text-green-100 hover:bg-green-700 hover:text-white transition-colors'
                ]">
                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-500">
                  <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                  </svg>
                </div>
                <span v-if="!collapsed" class="truncate">{{ userName }}</span>
                <svg v-if="!collapsed" class="ml-auto h-4 w-4 shrink-0" :class="{ 'rotate-180': userMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
              </button>

              <!-- User Dropdown -->
              <transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="transform opacity-0 scale-95"
                enter-to-class="transform opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="transform opacity-100 scale-100"
                leave-to-class="transform opacity-0 scale-95">
                <div v-if="userMenuOpen && !collapsed"
                     class="absolute bottom-full left-0 right-0 mb-2 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5">
                  <div class="px-4 py-3">
                    <p class="text-sm font-medium text-gray-900 truncate">{{ userName }}</p>
                    <p class="text-sm text-gray-500 truncate">{{ userEmail }}</p>
                  </div>
                  <div class="py-1">
                    <form @submit.prevent="logout">
                      <button
                        type="submit"
                        class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                        <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                        </svg>
                        Sair
                      </button>
                    </form>
                  </div>
                </div>
              </transition>
            </div>
          </div>
        </li>
      </ul>
    </nav>

    <!-- Collapse Toggle -->
    <div class="border-t border-green-700 p-4">
      <button
        @click="toggleCollapse"
        class="flex w-full items-center justify-center rounded-md p-2 text-green-100 hover:bg-green-700 hover:text-white transition-colors">
        <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': collapsed }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';

const emit = defineEmits(['toggle-collapse']);

const props = defineProps({
  collapsed: {
    type: Boolean,
    default: false
  }
});

const $page = usePage();
const userMenuOpen = ref(false);

// Navigation items
const navigationItems = [
  {
    name: 'Dashboard',
    href: '/',
    icon: 'DashboardIcon'
  },
  {
    name: 'Clientes',
    href: '/clients',
    icon: 'UsersIcon'
  },
  {
    name: 'Produtos',
    href: '/products',
    icon: 'CubeIcon'
  },
  {
    name: 'Ordens de Serviço',
    href: '/service-orders',
    icon: 'DocumentTextIcon'
  },
  {
    name: 'Certificados',
    href: '/certificates',
    icon: 'BadgeCheckIcon'
  },
];

const navigation = computed(() => {
  return navigationItems.map(item => ({
    ...item,
    current: $page.url === item.href
  }));
});

const userName = computed(() => $page.props.auth?.user?.name || 'Usuário');
const userEmail = computed(() => $page.props.auth?.user?.email || 'usuario@exemplo.com');

const toggleCollapse = () => {
  emit('toggle-collapse');
};

const toggleUserMenu = () => {
  if (!props.collapsed) {
    userMenuOpen.value = !userMenuOpen.value;
  }
};

const logout = () => {
  router.post('/logout');
};

// Close user menu when clicking outside
const handleClickOutside = (event) => {
  const sidebar = event.target.closest('.sidebar');
  if (!sidebar) {
    userMenuOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
