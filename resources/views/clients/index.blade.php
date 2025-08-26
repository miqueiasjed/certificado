@extends('layouts.app')

@section('content')
<div id="clients-app">
    <div class="bg-white shadow-sm rounded-lg">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-200 bg-green-50">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-900">Clientes</h2>
                <a href="{{ route('clients.create') }}"
                   class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors">
                    Novo Cliente
                </a>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex gap-4">
                <div class="flex-1">
                    <input type="text"
                           v-model="search"
                           @input="searchClients"
                           placeholder="Buscar por nome, email ou CPF/CNPJ..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                </div>
            </div>
        </div>

        <!-- Clients Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Telefone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CPF/CNPJ</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cidade</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($clients as $client)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $client->name }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ $client->email }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ $client->phone }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ $client->cnpj }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ $client->city }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="{{ route('clients.show', $client) }}"
                                   class="text-green-600 hover:text-green-900">Ver</a>
                                <a href="{{ route('clients.edit', $client) }}"
                                   class="text-blue-600 hover:text-blue-900">Editar</a>
                                <button @click="deleteClient({{ $client->id }})"
                                        class="text-red-600 hover:text-red-900">Excluir</button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($clients->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $clients->links() }}
        </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
const { createApp } = Vue;

createApp({
    data() {
        return {
            search: '',
            searchTimeout: null
        }
    },
    methods: {
        searchClients() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                if (this.search.length > 2 || this.search.length === 0) {
                    window.location.href = `{{ route('clients.index') }}?search=${this.search}`;
                }
            }, 500);
        },
        async deleteClient(clientId) {
            if (confirm('Tem certeza que deseja excluir este cliente?')) {
                try {
                    const response = await fetch(`/clients/${clientId}`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            'Accept': 'application/json',
                            'Content-Type': 'application/json'
                        }
                    });

                    const result = await response.json();

                    if (result.success) {
                        alert(result.message);
                        window.location.reload();
                    } else {
                        alert('Erro: ' + result.message);
                    }
                } catch (error) {
                    alert('Erro ao excluir cliente');
                }
            }
        }
    }
}).mount('#clients-app');
</script>
@endpush
@endsection
