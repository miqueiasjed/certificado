<template>
  <div class="flex h-full flex-col">
    <!-- Logo -->
    <div class="flex h-16 shrink-0 items-center border-b border-green-700 px-4">
      <Link href="/" class="flex items-center">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-green-500">
          <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
        </div>
        <span v-if="!collapsed" class="ml-3 text-lg font-semibold text-white">
          Sistema
        </span>
      </Link>
    </div>

    <!-- Navigation -->
    <nav class="flex flex-1 flex-col overflow-y-auto min-h-0 px-4 py-4">
      <ul role="list" class="flex flex-1 flex-col gap-y-1">
        <!-- Dashboard -->
        <li>
          <Link
            :href="'/dashboard'"
            :class="[
              isCurrentRoute('/dashboard')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/dashboard') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
            </svg>
            <span v-if="!collapsed">Dashboard</span>
          </Link>
        </li>

        <!-- Gestão de Clientes -->
        <li v-if="!collapsed && mostrarBlocoClientes" class="mt-4">
          <div class="text-xs font-semibold text-green-300 uppercase tracking-wider">Gestão de Clientes</div>
        </li>
        <li v-if="podeVerClientes">
          <Link
            :href="'/clients'"
            :class="[
              isCurrentRoute('/clients')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/clients') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0zM7 10a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
            </svg>
            <span v-if="!collapsed">Clientes</span>
          </Link>
        </li>
        <li v-if="podeVerEnderecos">
          <Link
            :href="'/addresses'"
            :class="[
              isCurrentRoute('/addresses')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/addresses') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            <span v-if="!collapsed">Endereços</span>
          </Link>
        </li>
        <li v-if="podeVerOrcamentos">
          <Link
            :href="'/budgets'"
            :class="[
              isCurrentRoute('/budgets')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/budgets') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span v-if="!collapsed">Orçamentos</span>
          </Link>
        </li>

        <!-- Atendimento e Agendamento Online -->
        <li v-if="!collapsed && mostrarBlocoAtendimento" class="mt-4">
          <div class="text-xs font-semibold text-green-300 uppercase tracking-wider">Atendimento</div>
        </li>
        <li v-if="podeVerSolicitacoes">
          <Link
            :href="'/solicitacoes'"
            :class="[
              isCurrentRoute('/solicitacoes')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex items-center gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/solicitacoes') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
            </svg>
            <span v-if="!collapsed" class="flex-1">Solicitações</span>
            <span
              v-if="!collapsed && solicitacoesAbertas > 0"
              class="inline-flex items-center justify-center h-5 min-w-5 px-1 rounded-full bg-red-500 text-white text-xs font-semibold">
              {{ solicitacoesAbertas }}
            </span>
          </Link>
        </li>
        <li v-if="podeVerAgendamentos">
          <Link
            :href="'/solicitacoes-de-horario'"
            :class="[
              isCurrentRoute('/solicitacoes-de-horario')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex items-center gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/solicitacoes-de-horario') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <span v-if="!collapsed" class="flex-1">Agendamentos</span>
            <span
              v-if="!collapsed && pedidosDeHorarioPendentes > 0"
              class="inline-flex items-center justify-center h-5 min-w-5 px-1 rounded-full bg-red-500 text-white text-xs font-semibold">
              {{ pedidosDeHorarioPendentes }}
            </span>
          </Link>
        </li>
        <li v-if="podeVerAgendamentos">
          <Link
            :href="'/satisfacao'"
            :class="[
              isCurrentRoute('/satisfacao')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/satisfacao') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3"></path>
            </svg>
            <span v-if="!collapsed">Satisfação</span>
          </Link>
        </li>

        <!-- Gestão Operacional -->
        <li v-if="!collapsed && mostrarBlocoOperacional" class="mt-4">
          <div class="text-xs font-semibold text-green-300 uppercase tracking-wider">Gestão Operacional</div>
        </li>
        <li v-if="podeVerCadastros">
          <Link
            :href="'/cadastros'"
            :class="[
              isCurrentRoute('/cadastros')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/cadastros') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
            </svg>
            <span v-if="!collapsed">Cadastros</span>
          </Link>
        </li>
        <li v-if="podeVerOrdensServico">
          <Link
            :href="'/work-orders'"
            :class="[
              isCurrentRoute('/work-orders')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/work-orders') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <span v-if="!collapsed">Ordens de Serviço</span>
          </Link>
        </li>
        <li v-if="podeVerOrdensServico">
          <Link
            :href="'/agenda'"
            :class="[
              isCurrentRoute('/agenda')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/agenda') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <span v-if="!collapsed">Agenda</span>
          </Link>
        </li>
        <li v-if="podeVerRoteiro">
          <Link
            :href="route('roteiros.painel')"
            :class="[
              isCurrentRoute('/roteiros')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/roteiros') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
            </svg>
            <span v-if="!collapsed">Roteiro do dia</span>
          </Link>
        </li>
        <li v-if="podeVerFrota">
          <Link
            :href="route('frota.index')"
            :class="[
              isCurrentRoute('/veiculos')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/veiculos') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 17a2 2 0 100-4 2 2 0 000 4zm8 0a2 2 0 100-4 2 2 0 000 4zM3 13h18l-1.5-5H4.5L3 13z"></path>
            </svg>
            <span v-if="!collapsed">Frota</span>
          </Link>
        </li>
        <li v-if="podeVerCertificados">
          <Link
            :href="'/certificates'"
            :class="[
              isCurrentRoute('/certificates')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/certificates') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span v-if="!collapsed">Certificados</span>
          </Link>
        </li>
        <li v-if="podeVerContratos">
          <Link
            :href="'/contracts'"
            :class="[
              isCurrentRoute('/contracts')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/contracts') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <span v-if="!collapsed">Contratos</span>
          </Link>
        </li>

        <!-- Gestão Financeira -->
        <li v-if="!collapsed && mostrarBlocoFinanceiro" class="mt-4">
          <div class="text-xs font-semibold text-green-300 uppercase tracking-wider">Financeiro e Fiscal</div>
        </li>
        <li v-if="podeVerFinanceiro">
          <Link
            :href="'/financial-dashboard'"
            :class="[
              isCurrentRoute('/financial-dashboard')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/financial-dashboard') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            <span v-if="!collapsed">Dashboard Financeiro</span>
          </Link>
        </li>
        <li v-if="podeVerFinanceiro">
          <Link
            :href="'/financial-entries'"
            :class="[
              isCurrentRoute('/financial-entries')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/financial-entries') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
            </svg>
            <span v-if="!collapsed">Entradas Financeiras</span>
          </Link>
        </li>
        <li v-if="podeVerFinanceiro">
          <Link
            :href="'/financial-withdrawals'"
            :class="[
              isCurrentRoute('/financial-withdrawals')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/financial-withdrawals') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4m16 0l-4-4m4 4l-4 4M4 12l4-4m-4 4l4 4"></path>
            </svg>
            <span v-if="!collapsed">Saídas Financeiras</span>
          </Link>
        </li>
        <li v-if="podeVerFinanceiro">
          <Link
            :href="'/cash-flow'"
            :class="[
              isCurrentRoute('/cash-flow')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/cash-flow') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            <span v-if="!collapsed">Fluxo de Caixa</span>
          </Link>
        </li>
        <li v-if="podeVerFiscal">
          <Link
            href="/notas"
            :class="[
              isCurrentRoute('/notas')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/notas') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 8h6m2 13H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z" />
            </svg>
            <span v-if="!collapsed">Notas fiscais</span>
          </Link>
        </li>
        <li v-if="podeConfigurarFiscal">
          <Link
            href="/fiscal/configuracao"
            :class="[
              isCurrentRoute('/fiscal/configuracao')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/fiscal/configuracao') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4v2m0-6V4m12 14a2 2 0 100-4m0 4v2m0-6V4" />
            </svg>
            <span v-if="!collapsed">Configuração fiscal</span>
          </Link>
        </li>

      </ul>
    </nav>

    <!-- User Menu (fixo, fora da área rolável do menu) -->
    <div class="shrink-0 border-t border-green-700 p-4">
      <div class="relative">
        <button
          @click="toggleUserMenu"
          :class="[
            'group flex w-full items-center gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 text-green-100 hover:bg-green-700 hover:text-white transition-colors'
          ]">
          <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-500">
            <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
            </svg>
          </div>
          <span v-if="!collapsed" class="truncate">{{ userName }}</span>
          <svg v-if="!collapsed" class="ml-auto h-4 w-4 shrink-0" :class="{ 'rotate-180': userMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
          </svg>
        </button>

        <!-- User Dropdown -->
        <transition
          enter-active-class="transition ease-out duration-100"
          enter-from-class="transform opacity-0 scale-95"
          enter-to-class="transform opacity-100 scale-100"
          leave-active-class="transition ease-in duration-75"
          leave-from-class="transform opacity-100 scale-100"
          leave-to-class="transform opacity-0 scale-95">
          <div v-if="userMenuOpen && !collapsed"
               class="absolute bottom-full left-0 right-0 mb-2 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5">
            <div class="px-4 py-3">
              <p class="text-sm font-medium text-gray-900 truncate">{{ userName }}</p>
              <p class="text-sm text-gray-500 truncate">{{ userEmail }}</p>
            </div>
            <div class="py-1">
              <Link
                v-if="podeConfigurarEmpresa"
                :href="route('settings.company.edit')"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Configurações
              </Link>
              <Link
                v-if="podeConfigurarDisponibilidade"
                :href="route('settings.disponibilidade.edit')"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                Disponibilidade
              </Link>
              <Link
                v-if="podeVerUsuarios"
                :href="route('settings.users.index')"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                </svg>
                Usuários
              </Link>
              <Link
                v-if="podeConvidarUsuarios"
                :href="route('settings.convites.index')"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 8v6m3-3h-6"></path>
                </svg>
                Convites
              </Link>
              <form @submit.prevent="logout">
                <button
                  type="submit"
                  class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                  <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                  </svg>
                  Sair
                </button>
              </form>
            </div>
          </div>
        </transition>
      </div>
    </div>

    <!-- Collapse Toggle -->
    <div class="border-t border-green-700 p-4">
      <button
        @click="toggleCollapse"
        class="flex w-full items-center justify-center rounded-md p-2 text-green-100 hover:bg-green-700 hover:text-white transition-colors">
        <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': collapsed }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { usePermissoes } from '@/Composables/usePermissoes';
import { useModulos } from '@/Composables/useModulos';

const emit = defineEmits(['toggle-collapse']);

const props = defineProps({
  collapsed: {
    type: Boolean,
    default: false
  }
});

const $page = usePage();
const userMenuOpen = ref(false);

const { pode } = usePermissoes();
const { temModulo } = useModulos();

// Gestão de Clientes
const podeVerClientes = computed(() => pode('cliente-ver'));
const podeVerEnderecos = computed(() => pode('endereco-ver'));
const podeVerOrcamentos = computed(() => pode('orcamento-ver'));
const mostrarBlocoClientes = computed(() =>
  podeVerClientes.value || podeVerEnderecos.value || podeVerOrcamentos.value
);

// Atendimento e Agendamento Online
const podeVerSolicitacoes = computed(() => pode('solicitacao-ver'));
const podeVerAgendamentos = computed(() => pode('agendamento-ver'));
const mostrarBlocoAtendimento = computed(() => podeVerSolicitacoes.value || podeVerAgendamentos.value);
const solicitacoesAbertas = computed(() => $page.props.solicitacoesAbertas || 0);
const pedidosDeHorarioPendentes = computed(() => $page.props.pedidosDeHorarioPendentes || 0);

// Gestão Operacional
const podeVerCadastros = computed(() => pode('cadastro-ver'));
const podeVerOrdensServico = computed(() => pode('ordem-servico-ver'));
const podeVerCertificados = computed(() => pode('certificado-ver'));
const podeVerContratos = computed(() => pode('contrato-ver') && temModulo('contratos'));
const podeVerRoteiro = computed(() => pode('roteiro-ver') && temModulo('roteirizacao'));
// Frota (Plano 27): o item só aparece com a permissão E com o módulo ligado.
// O módulo nasce desligado para todos os tenants, então a barra lateral
// continua exatamente como está hoje até alguém pedir a frota.
const podeVerFrota = computed(() => pode('frota-ver') && temModulo('frota'));
const mostrarBlocoOperacional = computed(() =>
  podeVerCadastros.value || podeVerOrdensServico.value || podeVerCertificados.value || podeVerContratos.value || podeVerRoteiro.value || podeVerFrota.value
);

// Gestão Financeira
const podeVerFinanceiro = computed(() => pode('financeiro-ver') && temModulo('financeiro'));
const podeVerFiscal = computed(() => pode('fiscal-ver') && temModulo('nfse'));
const podeConfigurarFiscal = computed(() => pode('fiscal-configurar') && temModulo('nfse'));
const mostrarBlocoFinanceiro = computed(() => podeVerFinanceiro.value || podeVerFiscal.value || podeConfigurarFiscal.value);

// Configurações da empresa
const podeConfigurarEmpresa = computed(() => pode('empresa-configurar'));
const podeConfigurarDisponibilidade = computed(() => pode('empresa-configurar'));
const podeVerUsuarios = computed(() => pode('usuario-ver'));
const podeConvidarUsuarios = computed(() => pode('usuario-criar'));

// Função para verificar se a rota atual corresponde ao link
const isCurrentRoute = (href) => {
  return $page.url === href || $page.url.startsWith(href + '/');
};

const userName = computed(() => $page.props.auth?.user?.name || 'Usuário');
const userEmail = computed(() => $page.props.auth?.user?.email || 'usuario@exemplo.com');

const toggleCollapse = () => {
  emit('toggle-collapse');
};

const toggleUserMenu = () => {
  if (!props.collapsed) {
    userMenuOpen.value = !userMenuOpen.value;
  }
};

const logout = () => {
  router.post('/logout');
};

// Close user menu when clicking outside
const handleClickOutside = (event) => {
  const sidebar = event.target.closest('.sidebar');
  if (!sidebar) {
    userMenuOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
