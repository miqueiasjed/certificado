<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Contas a Pagar" description="Parcelas a pagar aos fornecedores, com recorrência e baixa.">
        <template #actions>
          <button v-if="pode('financeiro-titulos')" type="button" class="btn-primary" @click="abrirCriacao">Novo título</button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Indicadores -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard title="Vence hoje" :value="formatarMoeda(indicadores.vence_hoje)" color="yellow" />
        <StatCard
          title="Vencido"
          :value="formatarMoeda(indicadores.vencido)"
          :subtitle="`${indicadores.parcelas_em_aberto} parcela(s) em aberto`"
          color="red"
        />
        <StatCard title="A vencer no mês" :value="formatarMoeda(indicadores.a_vencer_no_mes)" color="blue" />
        <StatCard title="Pago no mês" :value="formatarMoeda(indicadores.pago_no_mes)" color="green" />
      </div>

      <!-- Filtros -->
      <Card padding="small">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select
              v-model="filtros.situacao"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todas</option>
              <option v-for="opcao in SITUACOES" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Vencimento de</label>
            <input
              v-model="filtros.de"
              type="date"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Vencimento até</label>
            <input
              v-model="filtros.ate"
              type="date"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Fornecedor</label>
            <select
              v-model="filtros.supplier_id"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos</option>
              <option v-for="fornecedor in fornecedores" :key="fornecedor.id" :value="fornecedor.id">{{ fornecedor.nome }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Categoria</label>
            <select
              v-model="filtros.chart_of_account_id"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todas</option>
              <option v-for="categoria in categorias" :key="categoria.id" :value="categoria.id">{{ categoria.codigo }} - {{ categoria.nome }}</option>
            </select>
          </div>
        </div>
        <div class="mt-3 flex justify-end">
          <button type="button" class="btn-secondary-sm" @click="limparFiltros">Limpar filtros</button>
        </div>
      </Card>

      <!-- Lista de parcelas -->
      <Card padding="none">
        <div v-if="parcelas.length === 0" class="p-8 text-center text-sm text-gray-500">
          Nenhuma parcela a pagar encontrada para os filtros atuais.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fornecedor</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descrição</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vencimento</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Saldo</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr
                v-for="parcela in parcelas"
                :key="parcela.id"
                :class="parcela.vencida ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-gray-50'"
              >
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">{{ parcela.fornecedor }}</td>
                <td class="px-4 py-4 text-sm text-gray-700">
                  <div>{{ parcela.descricao }}</div>
                  <div class="mt-1 flex items-center gap-2">
                    <span v-if="parcela.categoria" class="text-xs text-gray-400">{{ parcela.categoria }}</span>
                    <span
                      v-if="parcela.recorrente"
                      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"
                      :title="`Recorrência ${RECORRENCIA_LABELS[parcela.recorrencia] || parcela.recorrencia}`"
                    >
                      Recorrente · {{ RECORRENCIA_LABELS[parcela.recorrencia] || parcela.recorrencia }}
                    </span>
                  </div>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm">
                  <span :class="parcela.vencida ? 'text-red-600 font-medium' : 'text-gray-900'">{{ formatarData(parcela.vencimento) }}</span>
                  <span v-if="parcela.vencida" class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    Vencida
                  </span>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-gray-900">{{ formatarMoeda(parcela.valor) }}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-gray-900">{{ formatarMoeda(parcela.saldo) }}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm">
                  <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium" :class="classeSituacao(parcela.situacao)">
                    {{ SITUACAO_LABELS[parcela.situacao] || parcela.situacao }}
                  </span>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-right">
                  <div class="flex items-center justify-end gap-3">
                    <button
                      v-if="podeBaixar(parcela) && pode('financeiro-baixar')"
                      type="button"
                      class="text-green-600 hover:text-green-900 transition-colors"
                      title="Registrar pagamento"
                      @click="abrirBaixa(parcela)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    </button>
                    <button
                      v-if="parcela.valor_pago > 0 && parcela.situacao !== 'cancelada' && pode('financeiro-estornar')"
                      type="button"
                      class="text-orange-600 hover:text-orange-900 transition-colors"
                      title="Estornar pagamento"
                      @click="abrirEstorno(parcela)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l-4-4m0 0l4-4m-4 4h11a4 4 0 010 8h-1" />
                      </svg>
                    </button>
                    <button
                      v-if="parcela.recorrente && pode('financeiro-titulos')"
                      type="button"
                      class="text-blue-600 hover:text-blue-900 transition-colors"
                      title="Alterar valor da série"
                      @click="abrirAlterarValor(parcela)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                    </button>
                    <button
                      v-if="['aberto', 'parcial'].includes(parcela.situacao_do_titulo) && pode('financeiro-titulos')"
                      type="button"
                      class="text-red-600 hover:text-red-900 transition-colors"
                      title="Cancelar título"
                      @click="pedirCancelamento(parcela)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="parcelas.length >= 300" class="px-4 py-3 text-xs text-gray-500 border-t border-gray-200">
            Exibindo os primeiros 300 registros. Refine os filtros para ver um recorte menor.
          </p>
        </div>
      </Card>
    </div>

    <!-- Novo título -->
    <Modal :show="mostrarCriacao" @close="fecharCriacao">
      <template #title>Novo título a pagar</template>
      <template #content>
        <div class="space-y-4 text-left">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Fornecedor *</label>
            <select
              v-model="form.supplier_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.supplier_id }"
            >
              <option value="">Selecione um fornecedor</option>
              <option v-for="fornecedor in fornecedores" :key="fornecedor.id" :value="fornecedor.id">{{ fornecedor.nome }}</option>
            </select>
            <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-red-600">{{ form.errors.supplier_id }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Descrição *</label>
            <input
              v-model="form.descricao"
              type="text"
              maxlength="255"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.descricao }"
            />
            <p v-if="form.errors.descricao" class="mt-1 text-sm text-red-600">{{ form.errors.descricao }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Categoria</label>
            <select
              v-model="form.chart_of_account_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Sem categoria</option>
              <option v-for="categoria in categorias" :key="categoria.id" :value="categoria.id">{{ categoria.codigo }} - {{ categoria.nome }}</option>
            </select>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Valor *</label>
              <input
                v-model="form.valor"
                type="number"
                step="0.01"
                min="0.01"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.valor }"
              />
              <p v-if="form.errors.valor" class="mt-1 text-sm text-red-600">{{ form.errors.valor }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Vencimento *</label>
              <input
                v-model="form.vencimento"
                type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.vencimento }"
              />
              <p v-if="form.errors.vencimento" class="mt-1 text-sm text-red-600">{{ form.errors.vencimento }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Emitido em</label>
            <input
              v-model="form.emitido_em"
              type="date"
              class="w-full sm:w-48 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <div class="border-t border-gray-200 pt-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Recorrência</label>
            <select
              v-model="form.recorrencia"
              class="w-full sm:w-64 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.recorrencia }"
            >
              <option value="nenhuma">Não recorrente</option>
              <option value="mensal">Mensal</option>
              <option value="trimestral">Trimestral</option>
              <option value="anual">Anual</option>
            </select>
            <p v-if="form.recorrencia !== 'nenhuma'" class="mt-2 text-xs text-gray-500">
              As próximas 3 competências desta série são geradas automaticamente ao salvar, e a rotina do sistema
              mantém sempre 3 à frente até o limite abaixo.
            </p>
            <div v-if="form.recorrencia !== 'nenhuma'" class="mt-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Recorrente até</label>
              <input
                v-model="form.recorrencia_ate"
                type="date"
                class="w-full sm:w-48 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              />
            </div>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="form.processing" @click="fecharCriacao">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="form.processing" @click="enviarCriacao">
          {{ form.processing ? 'Salvando...' : 'Cadastrar título' }}
        </button>
      </template>
    </Modal>

    <!-- Baixa -->
    <Modal :show="mostrarBaixa" @close="fecharBaixa">
      <template #title>Registrar pagamento</template>
      <template #content>
        <div v-if="parcelaSelecionada" class="space-y-4 text-left">
          <p class="text-sm text-gray-600">
            {{ parcelaSelecionada.fornecedor }} - {{ parcelaSelecionada.descricao }}
            <span class="block text-xs text-gray-400 mt-1">Vencimento {{ formatarData(parcelaSelecionada.vencimento) }}</span>
          </p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Valor pago *</label>
            <input
              v-model="baixaForm.valor"
              type="number"
              step="0.01"
              min="0.01"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': baixaForm.errors.valor }"
            />
            <p class="mt-1 text-xs text-gray-500">Saldo em aberto: {{ formatarMoeda(parcelaSelecionada.saldo) }}</p>
            <p v-if="baixaForm.errors.valor" class="mt-1 text-sm text-red-600">{{ baixaForm.errors.valor }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data do pagamento *</label>
            <input
              v-model="baixaForm.data"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': baixaForm.errors.data }"
            />
            <p v-if="baixaForm.errors.data" class="mt-1 text-sm text-red-600">{{ baixaForm.errors.data }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Forma de pagamento</label>
            <select
              v-model="baixaForm.forma_pagamento"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Não informado</option>
              <option value="pix">Pix</option>
              <option value="boleto">Boleto</option>
              <option value="transferencia">Transferência</option>
              <option value="dinheiro">Dinheiro</option>
              <option value="cartao">Cartão</option>
              <option value="outro">Outro</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
            <textarea
              v-model="baixaForm.observacao"
              rows="2"
              maxlength="1000"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            ></textarea>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="baixaForm.processing" @click="fecharBaixa">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="baixaForm.processing" @click="enviarBaixa">
          {{ baixaForm.processing ? 'Registrando...' : 'Registrar pagamento' }}
        </button>
      </template>
    </Modal>

    <!-- Estorno -->
    <Modal :show="mostrarEstorno" @close="fecharEstorno">
      <template #icon>
        <svg class="h-6 w-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l-4-4m0 0l4-4m-4 4h11a4 4 0 010 8h-1" />
        </svg>
      </template>
      <template #title>Estornar pagamento</template>
      <template #content>
        <div v-if="parcelaSelecionada" class="space-y-4 text-left">
          <p class="text-sm text-gray-600">
            Esta ação desfaz o pagamento já lançado no caixa de
            <strong>{{ parcelaSelecionada.fornecedor }}</strong> - {{ parcelaSelecionada.descricao }}.
          </p>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo do estorno *</label>
            <textarea
              v-model="estornoForm.motivo"
              rows="3"
              maxlength="1000"
              placeholder="Explique por que este pagamento está sendo desfeito (mínimo 10 caracteres)"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': estornoForm.errors.motivo }"
            ></textarea>
            <p v-if="estornoForm.errors.motivo" class="mt-1 text-sm text-red-600">{{ estornoForm.errors.motivo }}</p>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="estornoForm.processing" @click="fecharEstorno">Cancelar</button>
        <button type="button" class="btn-danger" :disabled="estornoForm.processing" @click="enviarEstorno">
          {{ estornoForm.processing ? 'Estornando...' : 'Confirmar estorno' }}
        </button>
      </template>
    </Modal>

    <!-- Alterar valor de recorrente -->
    <Modal :show="mostrarAlterarValor" @close="fecharAlterarValor">
      <template #title>Alterar valor da série</template>
      <template #content>
        <div v-if="parcelaSelecionada" class="space-y-4 text-left">
          <p class="text-sm text-gray-600">
            {{ parcelaSelecionada.fornecedor }} - {{ parcelaSelecionada.descricao }}
            <span class="block text-xs text-gray-400 mt-1">
              Recorrência {{ RECORRENCIA_LABELS[parcelaSelecionada.recorrencia] || parcelaSelecionada.recorrencia }}
            </span>
          </p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Novo valor *</label>
            <input
              v-model="alterarValorForm.valor"
              type="number"
              step="0.01"
              min="0.01"
              class="w-full sm:w-48 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': alterarValorForm.errors.valor }"
            />
            <p v-if="alterarValorForm.errors.valor" class="mt-1 text-sm text-red-600">{{ alterarValorForm.errors.valor }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Alcance da alteração *</label>
            <div class="space-y-2">
              <label class="flex items-start gap-2 p-3 border rounded-md cursor-pointer" :class="alterarValorForm.alcance === 'apenas_este' ? 'border-green-500 bg-green-50' : 'border-gray-300'">
                <input v-model="alterarValorForm.alcance" type="radio" value="apenas_este" class="mt-1" />
                <span class="text-sm text-gray-800">
                  <strong class="block">Apenas este título</strong>
                  Só esta competência passa a valer o novo valor. As próximas continuam com o valor atual.
                </span>
              </label>
              <label class="flex items-start gap-2 p-3 border rounded-md cursor-pointer" :class="alterarValorForm.alcance === 'este_e_futuros' ? 'border-green-500 bg-green-50' : 'border-gray-300'">
                <input v-model="alterarValorForm.alcance" type="radio" value="este_e_futuros" class="mt-1" />
                <span class="text-sm text-gray-800">
                  <strong class="block">Este e os títulos futuros da série</strong>
                  Esta e as próximas competências da série passam a valer o novo valor.
                </span>
              </label>
            </div>
            <p v-if="alterarValorForm.errors.alcance" class="mt-1 text-sm text-red-600">{{ alterarValorForm.errors.alcance }}</p>
          </div>

          <div class="rounded-md bg-blue-50 border border-blue-200 p-3">
            <p class="text-sm text-blue-800">{{ textoDoEfeito }}</p>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="alterarValorForm.processing" @click="fecharAlterarValor">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="alterarValorForm.processing" @click="enviarAlterarValor">
          {{ alterarValorForm.processing ? 'Aplicando...' : 'Confirmar alteração' }}
        </button>
      </template>
    </Modal>

    <!-- Cancelamento de título -->
    <ConfirmDeleteModal
      :show="!!tituloParaCancelar"
      title="Cancelar título"
      message="Tem certeza que deseja cancelar este título a pagar? As parcelas em aberto serão canceladas"
      :item-name="tituloParaCancelar?.descricao || ''"
      subtitle="Parcelas já pagas continuam como estão."
      confirm-text="Sim, cancelar"
      processing-text="Cancelando..."
      variant="danger"
      :processing="cancelando"
      @confirm="confirmarCancelamento"
      @cancel="tituloParaCancelar = null"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData, hojeISO } from '@/utils/formatDate';

const props = defineProps({
  filtros: Object,
  parcelas: Array,
  indicadores: Object,
  fornecedores: Array,
  categorias: Array,
});

const { pode } = usePermissoes();

const SITUACOES = [
  { valor: 'aberta', rotulo: 'Aberta' },
  { valor: 'parcial', rotulo: 'Parcial' },
  { valor: 'paga', rotulo: 'Paga' },
  { valor: 'vencida', rotulo: 'Vencida' },
  { valor: 'cancelada', rotulo: 'Cancelada' },
];

const SITUACAO_LABELS = Object.fromEntries(SITUACOES.map((opcao) => [opcao.valor, opcao.rotulo]));

const RECORRENCIA_LABELS = {
  nenhuma: 'Não recorrente',
  mensal: 'Mensal',
  trimestral: 'Trimestral',
  anual: 'Anual',
};

const classeSituacao = (situacao) => {
  switch (situacao) {
    case 'paga':
      return 'bg-green-100 text-green-800';
    case 'parcial':
      return 'bg-yellow-100 text-yellow-800';
    case 'vencida':
      return 'bg-red-100 text-red-800';
    case 'cancelada':
      return 'bg-gray-100 text-gray-800';
    default:
      return 'bg-blue-100 text-blue-800';
  }
};

const podeBaixar = (parcela) => ['aberta', 'parcial', 'vencida'].includes(parcela.situacao);

const formatarMoeda = (valor) => {
  if (valor === null || valor === undefined) return '-';

  return Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
};

// -----------------------------------------------------------------
// Filtros
// -----------------------------------------------------------------

const filtros = reactive({
  situacao: props.filtros.situacao || '',
  de: props.filtros.de || '',
  ate: props.filtros.ate || '',
  supplier_id: props.filtros.supplier_id ? String(props.filtros.supplier_id) : '',
  chart_of_account_id: props.filtros.chart_of_account_id ? String(props.filtros.chart_of_account_id) : '',
});

const aplicarFiltros = () => {
  const parametros = {};

  if (filtros.situacao) parametros.situacao = filtros.situacao;
  if (filtros.de) parametros.de = filtros.de;
  if (filtros.ate) parametros.ate = filtros.ate;
  if (filtros.supplier_id) parametros.supplier_id = filtros.supplier_id;
  if (filtros.chart_of_account_id) parametros.chart_of_account_id = filtros.chart_of_account_id;

  router.get(route('contas-a-pagar.index'), parametros, { preserveState: true, replace: true });
};

const limparFiltros = () => {
  filtros.situacao = '';
  filtros.de = '';
  filtros.ate = '';
  filtros.supplier_id = '';
  filtros.chart_of_account_id = '';
  aplicarFiltros();
};

// -----------------------------------------------------------------
// Criação de título
// -----------------------------------------------------------------

const mostrarCriacao = ref(false);

const form = useForm({
  supplier_id: '',
  chart_of_account_id: '',
  descricao: '',
  valor: '',
  vencimento: '',
  emitido_em: '',
  recorrencia: 'nenhuma',
  recorrencia_ate: '',
});

const abrirCriacao = () => {
  form.clearErrors();
  form.reset();
  form.vencimento = hojeISO();
  mostrarCriacao.value = true;
};

const fecharCriacao = () => {
  if (form.processing) return;
  mostrarCriacao.value = false;
};

const enviarCriacao = () => {
  form.post(route('contas-a-pagar.store'), {
    preserveScroll: true,
    onSuccess: () => fecharCriacao(),
  });
};

// -----------------------------------------------------------------
// Baixa (registrar pagamento)
// -----------------------------------------------------------------

const mostrarBaixa = ref(false);
const parcelaSelecionada = ref(null);

const baixaForm = useForm({
  valor: '',
  data: '',
  forma_pagamento: '',
  observacao: '',
});

const abrirBaixa = (parcela) => {
  parcelaSelecionada.value = parcela;
  baixaForm.clearErrors();
  baixaForm.valor = parcela.saldo;
  baixaForm.data = hojeISO();
  baixaForm.forma_pagamento = '';
  baixaForm.observacao = '';
  mostrarBaixa.value = true;
};

const fecharBaixa = () => {
  if (baixaForm.processing) return;
  mostrarBaixa.value = false;
  parcelaSelecionada.value = null;
};

const enviarBaixa = () => {
  baixaForm.post(route('contas-a-pagar.baixar', parcelaSelecionada.value.id), {
    preserveScroll: true,
    onSuccess: () => fecharBaixa(),
  });
};

// -----------------------------------------------------------------
// Estorno
// -----------------------------------------------------------------

const mostrarEstorno = ref(false);

const estornoForm = useForm({
  motivo: '',
});

const abrirEstorno = (parcela) => {
  parcelaSelecionada.value = parcela;
  estornoForm.clearErrors();
  estornoForm.motivo = '';
  mostrarEstorno.value = true;
};

const fecharEstorno = () => {
  if (estornoForm.processing) return;
  mostrarEstorno.value = false;
  parcelaSelecionada.value = null;
};

const enviarEstorno = () => {
  estornoForm.post(route('contas-a-pagar.estornar', parcelaSelecionada.value.id), {
    preserveScroll: true,
    onSuccess: () => fecharEstorno(),
  });
};

// -----------------------------------------------------------------
// Alterar valor de título recorrente, com escolha de alcance
// -----------------------------------------------------------------

const mostrarAlterarValor = ref(false);

const alterarValorForm = useForm({
  valor: '',
  alcance: 'apenas_este',
});

const textoDoEfeito = computed(() => {
  const valorFormatado = alterarValorForm.valor ? formatarMoeda(alterarValorForm.valor) : 'o novo valor';

  return alterarValorForm.alcance === 'este_e_futuros'
    ? `Ao confirmar, esta competência e todas as competências futuras já geradas desta série passam a valer ${valorFormatado}. Parcelas já pagas nunca mudam.`
    : `Ao confirmar, só esta competência passa a valer ${valorFormatado}. As próximas competências da série continuam com o valor atual.`;
});

const abrirAlterarValor = (parcela) => {
  parcelaSelecionada.value = parcela;
  alterarValorForm.clearErrors();
  alterarValorForm.valor = parcela.valor;
  alterarValorForm.alcance = 'apenas_este';
  mostrarAlterarValor.value = true;
};

const fecharAlterarValor = () => {
  if (alterarValorForm.processing) return;
  mostrarAlterarValor.value = false;
  parcelaSelecionada.value = null;
};

const enviarAlterarValor = () => {
  alterarValorForm.post(route('contas-a-pagar.valor', parcelaSelecionada.value.payable_id), {
    preserveScroll: true,
    onSuccess: () => fecharAlterarValor(),
  });
};

// -----------------------------------------------------------------
// Cancelamento de título (modal de confirmação do projeto, nunca confirm() nativo)
// -----------------------------------------------------------------

const tituloParaCancelar = ref(null);
const cancelando = ref(false);

const pedirCancelamento = (parcela) => {
  tituloParaCancelar.value = parcela;
};

const confirmarCancelamento = () => {
  cancelando.value = true;

  router.post(
    route('contas-a-pagar.cancelar', tituloParaCancelar.value.payable_id),
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        cancelando.value = false;
        tituloParaCancelar.value = null;
      },
    }
  );
};
</script>
