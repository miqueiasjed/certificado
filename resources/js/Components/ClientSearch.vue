<template>
  <div class="relative">
    <label v-if="label" class="block text-sm font-medium text-gray-700 mb-1">{{ label }}</label>
    
    <!-- Selected State -->
    <div v-if="modelValue" class="flex items-center justify-between px-3 py-2 border border-green-200 bg-green-50 rounded-md">
        <div class="flex flex-col">
            <span class="text-sm font-medium text-green-800">{{ selectedClientName }}</span>
             <span class="text-xs text-green-600">{{ selectedClientDetail }}</span>
        </div>
        <button type="button" @click="clearSelection" class="text-green-600 hover:text-green-800 p-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>

    <!-- Search Input -->
    <div v-else class="relative">
         <div class="relative">
            <input 
                type="text" 
                v-model="searchQuery" 
                @input="onInput"
                placeholder="Buscar por nome, CPF ou CNPJ..." 
                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm pl-10"
            />
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
             <div v-if="searchQuery && !isOpen" class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer" @click="searchQuery = ''">
                <svg class="h-4 w-4 text-gray-400 hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </div>
         </div>

        <!-- Dropdown Results -->
        <div v-if="isOpen" class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm">
            <div v-if="filteredClients.length === 0" class="px-4 py-2 text-sm text-gray-500">
                Nenhum cliente encontrado.
            </div>
            <div 
                v-for="client in filteredClients" 
                :key="client.id" 
                @click="selectClient(client)"
                class="cursor-pointer select-none relative py-2 pl-3 pr-9 hover:bg-green-50 text-gray-900"
            >
                <div class="flex flex-col">
                    <span class="font-medium">{{ client.name }}</span>
                    <span class="text-xs text-gray-500">{{ formatDocument(client.cnpj) }} {{ client.phone ? ` • ${client.phone}` : '' }}</span>
                </div>
            </div>
        </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  modelValue: [String, Number],
  clients: {
    type: Array,
    required: true
  },
  label: {
      type: String,
      default: 'Cliente'
  }
});

const emit = defineEmits(['update:modelValue', 'change']);

const searchQuery = ref('');
const isOpen = ref(false);

const selectedClientName = computed(() => {
    const client = props.clients.find(c => c.id === props.modelValue);
    return client ? client.name : '';
});

const selectedClientDetail = computed(() => {
    const client = props.clients.find(c => c.id === props.modelValue);
    if (!client) return '';
    return formatDocument(client.cnpj) || client.phone || client.email || 'Sem detalhes';
});

const filteredClients = computed(() => {
    if (!searchQuery.value) return [];
    
    const query = searchQuery.value.toLowerCase();
    return props.clients.filter(client => {
        const name = client.name ? client.name.toLowerCase() : '';
        const doc = client.cnpj ? client.cnpj.replace(/\D/g, '') : ''; // Search raw numbers
        // Also allow searching by formatted document if user typed formatting
        const docFormatted = client.cnpj || ''; 
        
        return name.includes(query) || doc.includes(query) || docFormatted.includes(query);
    }).slice(0, 10); // Limit results
});

const onInput = () => {
    isOpen.value = true;
};

const selectClient = (client) => {
    emit('update:modelValue', client.id);
    emit('change', client);
    isOpen.value = false;
    searchQuery.value = '';
};

const clearSelection = () => {
    emit('update:modelValue', null);
    emit('change', null);
};

const formatDocument = (doc) => {
    if (!doc) return '';
    // Basic formatting or just return as is if already formatted
    return doc;
};

// Close dropdown when clicking outside
const handleClickOutside = (event) => {
  if (!event.target.closest('.relative')) {
     if (isOpen.value) isOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
