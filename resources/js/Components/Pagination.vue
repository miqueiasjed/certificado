<template>
  <div v-if="links.length > 3" class="flex items-center justify-between">
    <div class="flex-1 flex justify-between sm:hidden">
      <Link
        v-if="links[0].url"
        :href="links[0].url"
        class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
      >
        Anterior
      </Link>
      <Link
        v-if="links[links.length - 1].url"
        :href="links[links.length - 1].url"
        class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
      >
        Próximo
      </Link>
    </div>
    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
      <div>
        <p class="text-sm text-gray-700">
          Mostrando
          <span class="font-medium">{{ paginationInfo.from }}</span>
          a
          <span class="font-medium">{{ paginationInfo.to }}</span>
          de
          <span class="font-medium">{{ paginationInfo.total }}</span>
          resultados
        </p>
      </div>
      <div>
        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
          <Link
            v-for="(link, key) in links"
            :key="key"
            :href="link.url"
            v-html="link.label"
            class="relative inline-flex items-center px-4 py-2 border text-sm font-medium"
            :class="[
              link.url === null
                ? 'text-gray-400 cursor-not-allowed'
                : 'text-gray-700 hover:bg-gray-50',
              link.active
                ? 'z-10 bg-green-50 border-green-500 text-green-600'
                : 'bg-white border-gray-300',
              key === 0 ? 'rounded-l-md' : '',
              key === links.length - 1 ? 'rounded-r-md' : '',
            ]"
          />
        </nav>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  links: Array,
});

const paginationInfo = computed(() => {
  const currentPage = props.links.find(link => link.active)?.label || 1;
  const total = props.links.find(link => link.label === '»')?.url?.match(/page=(\d+)/)?.[1] || 1;
  const perPage = 15; // Ajuste conforme necessário

  const from = (currentPage - 1) * perPage + 1;
  const to = Math.min(currentPage * perPage, total);

  return { from, to, total };
});
</script>
