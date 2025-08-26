<template>
  <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex-1 min-w-0">
      <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl sm:truncate">{{ title }}</h1>
      <p v-if="description" class="mt-1 text-sm text-gray-600 sm:text-base">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex flex-col gap-2 sm:flex-row sm:gap-3">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    required: true
  },
  description: {
    type: String,
    default: null
  }
});
</script>
