<template>
  <form @submit.prevent="submit" class="space-y-8">
    <!-- Cliente -->
    <Card title="Dados do Cliente">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="col-span-2">
           <ClientSearch 
             v-model="form.client_id"
             :clients="clients"
             label="Cliente Cadastrado"
             @change="onClientChange"
           />
        </div>

        <template v-if="!form.client_id">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Nome do Prospecto *</label>
            <input v-model="form.prospect_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm" />
            <div v-if="form.errors.prospect_name" class="text-red-500 text-xs mt-1">{{ form.errors.prospect_name }}</div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Telefone</label>
            <input v-model="form.prospect_phone" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm" />
          </div>
          <div class="col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">Endereço</label>
            <input v-model="form.prospect_address" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm" />
          </div>
        </template>
      </div>
    </Card>

    <!-- Detalhes do Orçamento -->
    <Card title="Detalhes do Serviço">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Data *</label>
          <input v-model="form.date" type="date" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Validade</label>
          <input v-model="form.validity_date" type="date" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Prioridade *</label>
          <select v-model="form.priority" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
            <option value="normal">Normal</option>
            <option value="urgent">Urgente</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Canais</label>
          <select v-model="form.channel" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
            <option value="WhatsApp">WhatsApp</option>
            <option value="Phone">Telefone</option>
            <option value="Site">Site</option>
            <option value="InPerson">Presencial</option>
            <option value="Referral">Indicação</option>
            <option value="Other">Outros</option>
          </select>
        </div>
        
         <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Ambiente</label>
          <select v-model="form.environment_type" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
            <option value="residential">Residencial</option>
            <option value="commercial">Comercial</option>
            <option value="industrial">Industrial</option>
             <option value="food">Alimentício</option>
            <option value="hospital">Hospitalar</option>
             <option value="school">Escola</option>
             <option value="condo">Condomínio</option>
             <option value="other">Outros</option>
          </select>
        </div>
      </div>
      
      <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
           <label class="block text-sm font-medium text-gray-700 mb-2">Pragas Alvo</label>
           <!-- Simple multi-select via checkboxes for now, or just json text input? Let's do checkboxes -->
           <div class="mt-2 space-y-2 max-h-40 overflow-y-auto border p-2 rounded">
             <div v-for="pest in pestsList" :key="pest">
                <label class="inline-flex items-center">
                  <input type="checkbox" :value="pest" v-model="form.target_pests" class="form-checkbox h-4 w-4 text-green-600">
                  <span class="ml-2 text-sm text-gray-700">{{ pest }}</span>
                </label>
             </div>
           </div>
        </div>
        
         <div>
           <label class="block text-sm font-medium text-gray-700 mb-2">Áreas a Tratar</label>
           <div class="mt-2 space-y-2 max-h-40 overflow-y-auto border p-2 rounded">
             <div v-for="area in areasList" :key="area">
                <label class="inline-flex items-center">
                  <input type="checkbox" :value="area" v-model="form.areas_to_treat" class="form-checkbox h-4 w-4 text-green-600">
                  <span class="ml-2 text-sm text-gray-700">{{ area }}</span>
                </label>
             </div>
           </div>
        </div>
      </div>
      
      <div class="mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
        <textarea v-model="form.observations" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm"></textarea>
      </div>
    </Card>

    <!-- Serviços -->
    <Card title="Serviços e Valores">
        <div class="space-y-4">
            <div v-for="(item, index) in form.items" :key="index" class="flex items-end gap-4 border-b pb-4">
                 <div class="flex-1">
                    <label class="block text-xs font-medium text-gray-500">Serviço</label>
                    <select v-model="item.service_id" @change="onServiceSelect(item)" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
                        <option :value="null">Selecione...</option>
                        <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                 </div>
                 <div class="w-24">
                    <label class="block text-xs font-medium text-gray-500">Qtd</label>
                    <input type="number" step="0.1" v-model="item.quantity" @input="calcSubtotal(item)" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
                 </div>
                 <div class="w-32">
                    <label class="block text-xs font-medium text-gray-500">Valor Unit.</label>
                    <input type="text" v-model="item.unit_price" @input="onUnitPriceInput($event, item)" @focus="selectAll" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm" placeholder="R$ 0,00">
                 </div>
                 <div class="w-32">
                    <label class="block text-xs font-medium text-gray-500">Subtotal</label>
                    <input type="text" disabled :value="formatCurrency(item.subtotal)" class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-md shadow-sm sm:text-sm">
                 </div>
                 <button type="button" @click="removeItem(index)" class="text-red-500 hover:text-red-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                 </button>
            </div>
            <button type="button" @click="addItem" class="text-sm text-green-600 font-medium hover:text-green-800">+ Adicionar Serviço</button>
        </div>
        
        <div class="mt-6 border-t pt-4 flex justify-end">
            <div class="w-64 space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="font-medium text-gray-500">Subtotal Serviços:</span>
                    <span>{{ formatCurrency(servicesTotal) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="font-medium text-gray-500 text-sm">Desconto:</span>
                    <input type="text" v-model="form.discount" @input="onDiscountInput" @focus="selectAll" class="w-32 px-3 py-2 text-right border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm" placeholder="R$ 0,00">
                </div>
                 <div class="flex justify-between text-lg font-bold text-gray-900 border-t pt-2">
                    <span>Total:</span>
                    <span>{{ formatCurrency(finalTotal) }}</span>
                </div>
            </div>
        </div>
    </Card>
    
    <!-- Produtos (Opcional) -->
    <Card title="Produtos Utilizados (Estimativa)">
         <div class="space-y-4">
             <div v-for="(prod, index) in form.products" :key="index" class="flex items-end gap-4 border-b pb-4">
                 <div class="flex-1">
                     <label class="block text-xs font-medium text-gray-500">Produto</label>
                     <select v-model="prod.product_id" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
                         <option :value="null">Selecione...</option>
                         <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                     </select>
                 </div>
                 <div class="w-24">
                     <label class="block text-xs font-medium text-gray-500">Qtd</label>
                     <input type="number" step="0.1" v-model="prod.quantity" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
                 </div>
                 <button type="button" @click="removeProduct(index)" class="text-red-500 hover:text-red-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                 </button>
             </div>
             <button type="button" @click="addProduct" class="text-sm text-green-600 font-medium hover:text-green-800">+ Adicionar Produto</button>
         </div>
    </Card>
    
    <!-- Comercial -->
    <Card title="Condições Comerciais e Execução">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                 <label class="block text-sm font-medium text-gray-700 mb-2">Estimativa Técnica</label>
                 <div class="flex space-x-2 mt-1">
                     <input v-model="form.labor_technicians" type="number" placeholder="Nº Técnicos" class="w-1/2 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
                     <input v-model="form.labor_hours" type="text" placeholder="Horas (ex: 2h30)" class="w-1/2 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
                 </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Custo Total (Interno)</label>
                <!-- Maybe calculate based on technicians * hours * rate? For now manual or hidden -->
                <p class="text-xs text-gray-500 mt-2">Pode ser usado para calcular margem.</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Forma de Pagamento</label>
                <select v-model="form.payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
                    <option value="Pix">Pix</option>
                    <option value="Cartão">Cartão de Crédito/Débito</option>
                    <option value="Boleto">Boleto</option>
                    <option value="Faturado">Faturado</option>
                    <option value="Dinheiro">Dinheiro</option>
                </select>
            </div>
             <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Condições de Pagamento</label>
                <input v-model="form.payment_conditions" type="text" placeholder="Ex: Entrada + 2x" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
            </div>
            
             <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Prazo de Execução</label>
                <input v-model="form.execution_deadline" type="text" placeholder="Ex: D+2 após aprovação" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
            </div>
            
            <div v-if="isEditing">
                 <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                  <select v-model="form.status" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm">
                    <option value="draft">Rascunho</option>
                    <option value="sent">Enviado</option>
                    <option value="negotiating">Negociando</option>
                    <option value="approved">Aprovado</option>
                    <option value="refused">Recusado</option>
                    <option value="converted">Convertido</option>
                  </select>
            </div>
        </div>
    </Card>

    <div class="flex justify-end space-x-4">
        <Link href="/budgets" class="btn-secondary">Cancelar</Link>
        <button type="submit" :disabled="form.processing" class="btn-primary">
            {{ isEditing ? 'Atualizar Orçamento' : 'Salvar Orçamento' }}
        </button>
    </div>
  </form>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import ClientSearch from '@/Components/ClientSearch.vue';
import { useMasks } from '@/Composables/useMasks';

const props = defineProps({
  budget: Object,
  clients: Array,
  services: Array,
  products: Array,
  isEditing: Boolean,
});

const { currencyMask, parseCurrency } = useMasks();

const form = useForm({
  client_id: props.budget?.client_id || null,
  prospect_name: props.budget?.prospect_name || '',
  prospect_phone: props.budget?.prospect_phone || '',
  prospect_address: props.budget?.prospect_address || '',
  prospect_address: props.budget?.prospect_address || '',
  date: props.budget?.date ? props.budget.date.substring(0, 10) : new Date().toISOString().substring(0, 10),
  validity_date: props.budget?.validity_date ? props.budget.validity_date.substring(0, 10) : '',
  priority: props.budget?.priority || 'normal',
  channel: props.budget?.channel || '',
  target_pests: props.budget?.target_pests || [],
  environment_type: props.budget?.environment_type || '',
  areas_to_treat: props.budget?.areas_to_treat || [],
  observations: props.budget?.observations || '',
  discount: props.budget?.discount ? currencyMask(props.budget.discount * 100) : '', // Assuming backend sends float, mask expects input-like typing or we adapt
  // Actually currencyMask expects a string or number and treats as cents if integer string, 
  // but if we pass float 150.00, mask might not work as expected if it just does replace(/\D/).
  // Our mask logic: replace \D -> integer -> /100.
  // So 150.00 -> 15000 -> 150,00. Correct.
  
  // Arrays
  items: props.budget?.services?.map(s => ({
      service_id: s.id,
      quantity: s.pivot.quantity,
      unit_price: currencyMask(Math.round(s.pivot.unit_price * 100)), // Convert to cents integer for mask
      subtotal: s.pivot.quantity * s.pivot.unit_price // Recalculate based on values, don't trust DB subtotal which might be corrupted
  })) || [],
  
  products: props.budget?.products?.map(p => ({
      product_id: p.id,
      quantity: p.pivot.quantity
  })) || [],
  
  labor_technicians: props.budget?.labor_technicians || 1,
  labor_hours: props.budget?.labor_hours || '',
  payment_method: props.budget?.payment_method || '',
  payment_conditions: props.budget?.payment_conditions || '',
  execution_deadline: props.budget?.execution_deadline || '',
  
  status: props.budget?.status || 'draft',
  loss_reason: props.budget?.loss_reason || '',
});

const submit = () => {
  // Transform masked values back to numbers
  const transformedForm = form.transform((data) => ({
      ...data,
      discount: parseCurrency(data.discount),
      items: data.items.map(item => ({
          ...item,
          unit_price: parseCurrency(item.unit_price),
          subtotal: parseCurrency(item.subtotal) // ensure subtotal is number (it might be number already but let's be safe)
      }))
  }));

  if (props.isEditing) {
    transformedForm.put(`/budgets/${props.budget.id}`);
  } else {
    transformedForm.post('/budgets');
  }
};

// Lists
const pestsList = ['Baratas', 'Formigas', 'Mosquitos', 'Roedores', 'Cupins', 'Pulgas', 'Carrapatos', 'Escorpiões', 'Outros'];
const areasList = ['Interna', 'Externa', 'Jardim', 'Forro', 'Caixa de Gordura', 'Ralos', 'Lixeiras', 'Subsolo', 'Outros'];

// Logics
const addItem = () => {
    form.items.push({ service_id: null, quantity: 1, unit_price: '', subtotal: 0 });
};
const removeItem = (index) => form.items.splice(index, 1);

const addProduct = () => {
    form.products.push({ product_id: null, quantity: 1 });
};
const removeProduct = (index) => form.products.splice(index, 1);

const onServiceSelect = (item) => {
    // const s = props.services.find(s => s.id === item.service_id);
    // if (s) {
       // Optional: set default price if available
    // }
};

const onUnitPriceInput = (event, item) => {
    const newVal = currencyMask(event.target.value);
    item.unit_price = newVal;
    calcSubtotal(item);
};

const onDiscountInput = (event) => {
    form.discount = currencyMask(event.target.value);
};

const calcSubtotal = (item) => {
    const qty = parseFloat(item.quantity) || 0;
    const price = parseCurrency(item.unit_price);
    item.subtotal = qty * price;
};

const servicesTotal = computed(() => {
    return form.items.reduce((acc, item) => acc + (parseFloat(item.subtotal) || 0), 0);
});

const finalTotal = computed(() => {
    const discount = parseCurrency(form.discount);
    return servicesTotal.value - discount;
});

const formatCurrency = (val) => {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val);
};

const selectAll = (event) => {
    event.target.select();
};

// Watch for client changes to auto-fill prospect fields if needed? 
// No, prospect fields are valid only for NEW clients. If existing client selected, we ignore prospect fields in backend.
</script>
