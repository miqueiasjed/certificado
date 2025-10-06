<template>
  <div class="border-b border-gray-200 overflow-hidden">
    <div class="overflow-x-auto scrollbar-hide">
      <nav class="-mb-px flex space-x-8 min-w-max" aria-label="Tabs">
      <button
        v-for="tab in tabs"
        :key="tab.name"
        @click="$emit('tab-change', tab.name)"
        :class="[
          activeTab === tab.name
            ? 'border-green-500 text-green-600'
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
          'whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm flex-shrink-0'
        ]"
      >
        {{ tab.label }}
        <span v-if="tab.count !== undefined" class="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs font-medium">
          {{ tab.count }}
        </span>
      </button>
      </nav>
    </div>
  </div>
</template>

<script setup>
defineProps({
  tabs: {
    type: Array,
    required: true
  },
  activeTab: {
    type: String,
    required: true
  }
});

defineEmits(['tab-change']);
</script>

<style scoped>
.scrollbar-hide {
  -ms-overflow-style: none;  /* IE and Edge */
  scrollbar-width: none;  /* Firefox */
}

.scrollbar-hide::-webkit-scrollbar {
  display: none;  /* Chrome, Safari and Opera */
}

/* Smooth scrolling */
nav {
  scroll-behavior: smooth;
}
</style>



