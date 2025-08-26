<template>
  <Card variant="hover" class="overflow-hidden">
    <div class="flex items-center">
      <div class="flex-shrink-0">
        <div :class="iconWrapperClasses">
          <slot name="icon">
            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
          </slot>
        </div>
      </div>
      <div class="ml-4 flex-1">
        <p class="text-sm font-medium text-gray-600">{{ title }}</p>
        <p class="text-2xl font-semibold text-gray-900">{{ value }}</p>
        <p v-if="subtitle" class="text-xs text-gray-500 mt-1">{{ subtitle }}</p>
      </div>
      <div v-if="trend" class="flex-shrink-0">
        <div :class="trendClasses">
          <svg v-if="trend.direction === 'up'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
          </svg>
          <svg v-else-if="trend.direction === 'down'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path>
          </svg>
          <span class="text-xs font-medium">{{ trend.value }}</span>
        </div>
      </div>
    </div>
  </Card>
</template>

<script setup>
import { computed } from 'vue';
import Card from './Card.vue';

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  value: {
    type: [String, Number],
    required: true
  },
  subtitle: {
    type: String,
    default: null
  },
  color: {
    type: String,
    default: 'gray',
    validator: (value) => ['gray', 'green', 'blue', 'yellow', 'purple', 'red'].includes(value)
  },
  trend: {
    type: Object,
    default: null,
    validator: (value) => {
      if (!value) return true;
      return value.direction && ['up', 'down', 'neutral'].includes(value.direction) && value.value;
    }
  }
});

const iconWrapperClasses = computed(() => {
  const baseClasses = 'w-12 h-12 rounded-lg flex items-center justify-center';
  const colorClasses = {
    gray: 'bg-gray-100',
    green: 'bg-green-100',
    blue: 'bg-blue-100',
    yellow: 'bg-yellow-100',
    purple: 'bg-purple-100',
    red: 'bg-red-100'
  };

  return `${baseClasses} ${colorClasses[props.color]}`;
});

const trendClasses = computed(() => {
  if (!props.trend) return '';

  const baseClasses = 'flex items-center space-x-1 px-2 py-1 rounded-full text-xs';
  const colorClasses = {
    up: 'bg-green-100 text-green-800',
    down: 'bg-red-100 text-red-800',
    neutral: 'bg-gray-100 text-gray-800'
  };

  return `${baseClasses} ${colorClasses[props.trend.direction]}`;
});
</script>
