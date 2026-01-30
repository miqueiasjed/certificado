<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Configurações da Empresa"
        description="Gerencie as informações da empresa, logomarca e assinaturas exibidas nos documentos."
      />
    </template>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8 space-y-6">
      
      <!-- Mensagem de Sucesso -->
      <div v-if="$page.props.flash.success" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
          <span class="block sm:inline">{{ $page.props.flash.success }}</span>
      </div>

      <form @submit.prevent="submit" class="space-y-6">
        
        <!-- Dados Gerais -->
        <Card title="Dados Gerais">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Razão Social *</label>
              <input v-model="form.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" required />
              <div v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">CNPJ</label>
              <input v-model="form.cnpj" @input="onCnpjInput" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" />
              <div v-if="form.errors.cnpj" class="text-red-500 text-xs mt-1">{{ form.errors.cnpj }}</div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
              <input v-model="form.email" type="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Telefone</label>
              <input v-model="form.phone" @input="onPhoneInput" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" />
            </div>
          </div>
        </Card>

        <!-- Endereço -->
        <Card title="Endereço">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="col-span-1 md:col-span-3">
              <label class="block text-sm font-medium text-gray-700 mb-2">CEP</label>
              <div class="flex items-center gap-2">
                 <input v-model="form.zip" @input="onCepInput" type="text" class="w-32 px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" @blur="fetchAddress" />
                 <span v-if="loadingAddress" class="text-sm text-gray-500">Buscando...</span>
              </div>
            </div>

            <div class="col-span-1 md:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-2">Rua</label>
              <input v-model="form.street" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Número</label>
              <input v-model="form.number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" />
            </div>

            <div class="col-span-1 md:col-span-2">
               <label class="block text-sm font-medium text-gray-700 mb-2">Complemento</label>
               <input v-model="form.complement" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Bairro</label>
              <input v-model="form.district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Cidade</label>
              <input v-model="form.city" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Estado (UF)</label>
              <input v-model="form.state" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" maxlength="2" />
            </div>
          </div>
        </Card>

        <!-- Informações Legais -->
        <Card title="Informações Legais e Técnicas">
           <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
             <div>
               <label class="block text-sm font-medium text-gray-700 mb-2">Registro CRQ (Conselho Regional de Química)</label>
               <input v-model="form.crq" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" placeholder="Ex: 10º REGIÃO Nº 5.253" />
             </div>

             <div>
               <label class="block text-sm font-medium text-gray-700 mb-2">Licença Ambiental</label>
               <input v-model="form.license_environmental" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" placeholder="Ex: 177/2024" />
             </div>

             <div>
               <label class="block text-sm font-medium text-gray-700 mb-2">Alvará Sanitário</label>
               <input v-model="form.license_sanitary" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" placeholder="Ex: 062/2025" />
             </div>

             <div>
               <label class="block text-sm font-medium text-gray-700 mb-2">Alvará de Funcionamento</label>
               <input v-model="form.license_business" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" placeholder="Ex: 12345/2025" />
             </div>
             
             <div class="col-span-1 md:col-span-2">
               <label class="block text-sm font-medium text-gray-700 mb-2">Informações CEATOX (Rodapé)</label>
               <textarea v-model="form.ceatox_info" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" placeholder="Mensagem padrão de emergência toxicológica..."></textarea>
             </div>
           </div>
        </Card>

        <!-- Imagens e Assinaturas -->
        <Card title="Imagens e Assinaturas">
           <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
             
             <!-- Logo -->
             <div class="border p-4 rounded-lg bg-gray-50 flex flex-col items-center">
                <label class="block text-sm font-medium text-gray-700 mb-4">Logomarca da Empresa</label>
                
                <div v-if="logoPreview || (company && company.logo_path)" class="mb-4">
                  <img :src="logoPreview || (company ? '/storage/' + company.logo_path : '')" class="h-32 object-contain mx-auto" alt="Logo Preview">
                </div>
                <div v-else class="h-32 w-full bg-gray-200 flex items-center justify-center text-gray-400 mb-4 rounded">
                  Sem logo
                </div>

                <input type="file" ref="logoInput" @change="handleLogoChange" class="text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100" accept="image/*" />
             </div>

             <!-- Assinatura Gerente -->
             <div class="border p-4 rounded-lg bg-gray-50 flex flex-col items-center">
                <label class="block text-sm font-medium text-gray-700 mb-4">Assinatura Gerente Operacional</label>
                
                <div v-if="sigOpPreview || (company && company.signature_operational_path)" class="mb-4">
                  <img :src="sigOpPreview || (company ? '/storage/' + company.signature_operational_path : '')" class="h-32 object-contain mx-auto" alt="Signature Preview">
                </div>
                <div v-else class="h-32 w-full bg-gray-200 flex items-center justify-center text-gray-400 mb-4 rounded">
                   Sem assinatura
                </div>

                <input type="file" @change="handleSigOpChange" class="text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100" accept="image/*" />
             </div>

             <!-- Assinatura Químico -->
             <div class="border p-4 rounded-lg bg-gray-50 flex flex-col items-center">
                <label class="block text-sm font-medium text-gray-700 mb-4">Assinatura Químico Responsável</label>
                
                <div v-if="sigChemPreview || (company && company.signature_chemical_path)" class="mb-4">
                  <img :src="sigChemPreview || (company ? '/storage/' + company.signature_chemical_path : '')" class="h-32 object-contain mx-auto" alt="Signature Preview">
                </div>
                <div v-else class="h-32 w-full bg-gray-200 flex items-center justify-center text-gray-400 mb-4 rounded">
                   Sem assinatura
                </div>

                <input type="file" @change="handleSigChemChange" class="text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100" accept="image/*" />
             </div>



             <!-- Assinatura Responsável Orçamento -->
             <div class="border p-4 rounded-lg bg-gray-50 flex flex-col items-center">
                <label class="block text-sm font-medium text-gray-700 mb-4 text-center">Assinatura Responsável Orçamento</label>
                
                <div v-if="sigResponsiblePreview || (company && company.signature_responsible_path)" class="mb-4">
                  <img :src="sigResponsiblePreview || (company ? '/storage/' + company.signature_responsible_path : '')" class="h-32 object-contain mx-auto" alt="Signature Preview">
                </div>
                <div v-else class="h-32 w-full bg-gray-200 flex items-center justify-center text-gray-400 mb-4 rounded">
                   Sem assinatura
                </div>

                <input type="file" @change="handleSigResponsibleChange" class="text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100" accept="image/*" />
             </div>

           </div>
        </Card>

        <div class="flex justify-end">
          <button type="submit" :disabled="form.processing" class="bg-green-600 text-white px-6 py-3 rounded-md font-medium hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors shadow-sm disabled:opacity-50">
             {{ form.processing ? 'Salvando...' : 'Salvar Configurações' }}
          </button>
        </div>

      </form>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { useMasks } from '@/Composables/useMasks';

const props = defineProps({
  company: Object,
});

const { cpfCnpjMask, phoneMask, cepMask } = useMasks();

const form = useForm({
  name: props.company?.name || '',
  cnpj: props.company?.cnpj || '',
  email: props.company?.email || '',
  phone: props.company?.phone || '',
  street: props.company?.street || '',
  number: props.company?.number || '',
  complement: props.company?.complement || '',
  district: props.company?.district || '',
  city: props.company?.city || '',
  state: props.company?.state || '',
  zip: props.company?.zip || '',
  crq: props.company?.crq || '',
  license_environmental: props.company?.license_environmental || '',
  license_sanitary: props.company?.license_sanitary || '',
  license_business: props.company?.license_business || '',
  ceatox_info: props.company?.ceatox_info || '',
  logo_path: null,
  signature_operational_path: null,
  signature_chemical_path: null,
  signature_responsible_path: null,
});

// Previews
const logoPreview = ref(props.company?.logo_path ? `/storage/${props.company.logo_path}` : null);
const sigOpPreview = ref(props.company?.signature_operational_path ? `/storage/${props.company.signature_operational_path}` : null);
const sigChemPreview = ref(props.company?.signature_chemical_path ? `/storage/${props.company.signature_chemical_path}` : null);
const sigResponsiblePreview = ref(props.company?.signature_responsible_path ? `/storage/${props.company.signature_responsible_path}` : null);

const handleLogoChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.logo_path = file;
        logoPreview.value = URL.createObjectURL(file);
    }
};

const handleSigOpChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.signature_operational_path = file;
        sigOpPreview.value = URL.createObjectURL(file);
    }
};

const handleSigChemChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.signature_chemical_path = file;
        sigChemPreview.value = URL.createObjectURL(file);
    }
};

const handleSigResponsibleChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.signature_responsible_path = file;
        sigResponsiblePreview.value = URL.createObjectURL(file);
    }
};

const fetchAddress = async () => {
    const cep = form.zip.replace(/\D/g, '');
    if (cep.length === 8) {
        try {
            const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
            const data = await response.json();
            if (!data.erro) {
                form.street = data.logradouro;
                form.district = data.bairro;
                form.city = data.localidade;
                form.state = data.uf;
                document.getElementById('number').focus();
            }
        } catch (error) {
            console.error('Erro ao buscar CEP:', error);
        }
    }
};

const onCnpjInput = (e) => { form.cnpj = cpfCnpjMask(e.target.value); };
const onPhoneInput = (e) => { form.phone = phoneMask(e.target.value); };
const onCepInput = (e) => { form.zip = cepMask(e.target.value); };

const submit = () => {
  form.post(route('settings.company.update'), {
    preserveScroll: true,
    onSuccess: () => {
        // Optional: show toast
    },
  });
};
</script>

<style scoped>
/* Scoped styles */
</style>
