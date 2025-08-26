<template>
  <Link :href="href" :class="cardClasses">
    <div class="flex items-center gap-3">
      <div :class="iconWrapperClasses">
        <slot name="icon">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
        </slot>
      </div>
      <div class="flex-1 min-w-0">
        <h4 class="font-semibold leading-none tracking-tight text-sm text-gray-900 truncate">{{ title }}</h4>
        <p class="text-xs text-gray-500 mt-1 truncate">{{ description }}</p>
      </div>
      <div class="flex-shrink-0">
        <svg class="w-4 h-4 text-gray-400 group-hover:text-gray-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
      </div>
    </div>
  </Link>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  description: {
    type: String,
    required: true
  },
  href: {
    type: String,
    required: true
  },
  color: {
    type: String,
    default: 'gray',
    validator: (value) => ['gray', 'green', 'blue', 'yellow', 'purple', 'red'].includes(value)
  }
});

const cardClasses = computed(() => {
  const baseClasses = 'group relative overflow-hidden rounded-lg border bg-white p-4 transition-all duration-200';
  const hoverClasses = {
    gray: 'hover:bg-gray-50 hover:border-gray-300',
    green: 'hover:bg-green-50 hover:border-green-200',
    blue: 'hover:bg-blue-50 hover:border-blue-200',
    yellow: 'hover:bg-yellow-50 hover:border-yellow-200',
    purple: 'hover:bg-purple-50 hover:border-purple-200',
    red: 'hover:bg-red-50 hover:border-red-200'
  };

  return `${baseClasses} border-gray-200 ${hoverClasses[props.color]}`;
});

const iconWrapperClasses = computed(() => {
  const baseClasses = 'flex h-10 w-10 items-center justify-center rounded-lg transition-colors';
  const colorClasses = {
    gray: 'bg-gray-100 group-hover:bg-gray-200',
    green: 'bg-green-100 group-hover:bg-green-200',
    blue: 'bg-blue-100 group-hover:bg-blue-200',
    yellow: 'bg-yellow-100 group-hover:bg-yellow-200',
    purple: 'bg-purple-100 group-hover:bg-purple-200',
    red: 'bg-red-100 group-hover:bg-red-200'
  };

  const iconColors = {
    gray: 'text-gray-600',
    green: 'text-green-600',
    blue: 'text-blue-600',
    yellow: 'text-yellow-600',
    purple: 'text-purple-600',
    red: 'text-red-600'
  };

  return `${baseClasses} ${colorClasses[props.color]} ${iconColors[props.color]}`;
});
</script>
