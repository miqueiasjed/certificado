<template>
  <div :class="cardClasses">
    <slot />
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  variant: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'bordered', 'shadow', 'hover'].includes(value)
  },
  padding: {
    type: String,
    default: 'normal',
    validator: (value) => ['none', 'small', 'normal', 'large'].includes(value)
  },
  rounded: {
    type: String,
    default: 'lg',
    validator: (value) => ['none', 'sm', 'md', 'lg', 'xl', 'full'].includes(value)
  }
});

const cardClasses = computed(() => {
  const classes = ['bg-white'];

  // Variants
  switch (props.variant) {
    case 'bordered':
      classes.push('border border-gray-200');
      break;
    case 'shadow':
      classes.push('shadow-lg border border-gray-100');
      break;
    case 'hover':
      classes.push('border border-gray-200 hover:shadow-md hover:border-gray-300 transition-all duration-200');
      break;
    default:
      classes.push('shadow-sm border border-gray-200');
  }

  // Padding
  switch (props.padding) {
    case 'none':
      break;
    case 'small':
      classes.push('p-4');
      break;
    case 'large':
      classes.push('p-8');
      break;
    default:
      classes.push('p-6');
  }

  // Rounded
  switch (props.rounded) {
    case 'none':
      break;
    case 'sm':
      classes.push('rounded-sm');
      break;
    case 'md':
      classes.push('rounded-md');
      break;
    case 'xl':
      classes.push('rounded-xl');
      break;
    case 'full':
      classes.push('rounded-full');
      break;
    default:
      classes.push('rounded-lg');
  }

  return classes.join(' ');
});
</script>
