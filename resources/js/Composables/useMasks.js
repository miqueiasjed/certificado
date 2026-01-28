import { ref, computed } from 'vue';

export function useMasks() {
  // Máscara para telefone
  const phoneMask = (value) => {
    if (!value) return '';

    // Remove tudo que não é número
    let numbers = value.replace(/\D/g, '');

    // Limita a 11 dígitos
    if (numbers.length > 11) {
      numbers = numbers.slice(0, 11);
    }

    // Aplica a máscara
    if (numbers.length === 0) return '';
    if (numbers.length <= 2) return `(${numbers}`;
    if (numbers.length <= 7) return `(${numbers.slice(0, 2)}) ${numbers.slice(2)}`;
    if (numbers.length <= 11) return `(${numbers.slice(0, 2)}) ${numbers.slice(2, 7)}-${numbers.slice(7)}`;

    return value;
  };

  // Máscara para CPF/CNPJ
  const cpfCnpjMask = (value) => {
    if (!value) return '';

    // Remove tudo que não é número
    const numbers = value.replace(/\D/g, '');

    // Limita a 14 dígitos
    if (numbers.length > 14) return value.slice(0, 18);

    // Aplica máscara de CPF (11 dígitos)
    if (numbers.length <= 11) {
      if (numbers.length <= 3) return numbers;
      if (numbers.length <= 6) return `${numbers.slice(0, 3)}.${numbers.slice(3)}`;
      if (numbers.length <= 9) return `${numbers.slice(0, 3)}.${numbers.slice(3, 6)}.${numbers.slice(6)}`;
      if (numbers.length <= 11) return `${numbers.slice(0, 3)}.${numbers.slice(3, 6)}.${numbers.slice(6, 9)}-${numbers.slice(9)}`;
    }

    // Aplica máscara de CNPJ (14 dígitos)
    if (numbers.length <= 14) {
      if (numbers.length <= 2) return numbers;
      if (numbers.length <= 5) return `${numbers.slice(0, 2)}.${numbers.slice(2)}`;
      if (numbers.length <= 8) return `${numbers.slice(0, 2)}.${numbers.slice(2, 5)}.${numbers.slice(5)}`;
      if (numbers.length <= 12) return `${numbers.slice(0, 2)}.${numbers.slice(2, 5)}.${numbers.slice(5, 8)}/${numbers.slice(8)}`;
      if (numbers.length <= 14) return `${numbers.slice(0, 2)}.${numbers.slice(2, 5)}.${numbers.slice(5, 8)}/${numbers.slice(8, 12)}-${numbers.slice(12)}`;
    }

    return value;
  };

  // Máscara para CEP
  const cepMask = (value) => {
    if (!value) return '';

    // Remove tudo que não é número
    const numbers = value.replace(/\D/g, '');

    // Limita a 8 dígitos
    if (numbers.length > 8) return value.slice(0, 9);

    // Aplica a máscara
    if (numbers.length <= 5) return numbers;
    if (numbers.length <= 8) return `${numbers.slice(0, 5)}-${numbers.slice(5)}`;

    return value;
  };

  // Máscara para Moeda (R$)
  const currencyMask = (value) => {
    if (value === undefined || value === null || value === '') return '';

    // Converte para string para manipulação
    let stringValue = value.toString();

    // Remove tudo que não é número
    const numbers = stringValue.replace(/\D/g, '');

    if (numbers === '') return '';

    // Converte para centavos e depois para formato brasileiro
    const numericValue = parseInt(numbers) / 100;
    return numericValue.toLocaleString('pt-BR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  };

  const parseCurrency = (value) => {
    if (!value) return 0;
    if (typeof value === 'number') return value;
    const cleanValue = value.replace(/[^\d,]/g, '').replace(',', '.');
    return parseFloat(cleanValue) || 0;
  };

  // Função para aplicar máscara em tempo real
  const applyMask = (value, maskType) => {
    switch (maskType) {
      case 'phone':
        return phoneMask(value);
      case 'cpfCnpj':
        return cpfCnpjMask(value);
      case 'cep':
        return cepMask(value);
      default:
        return value;
    }
  };

  return {
    phoneMask,
    cpfCnpjMask,
    cepMask,
    applyMask,
    currencyMask,
    parseCurrency
  };
}
