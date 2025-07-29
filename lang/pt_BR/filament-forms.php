<?php

return [
    'actions' => [
        'save' => [
            'label' => 'Salvar',
        ],
        'cancel' => [
            'label' => 'Cancelar',
        ],
        'create' => [
            'label' => 'Criar',
        ],
        'edit' => [
            'label' => 'Editar',
        ],
        'delete' => [
            'label' => 'Excluir',
        ],
    ],
    'fields' => [
        'name' => [
            'label' => 'Nome',
        ],
        'email' => [
            'label' => 'E-mail',
        ],
        'password' => [
            'label' => 'Senha',
        ],
        'password_confirmation' => [
            'label' => 'Confirmar senha',
        ],
        'created_at' => [
            'label' => 'Criado em',
        ],
        'updated_at' => [
            'label' => 'Atualizado em',
        ],
    ],
    'validation' => [
        'required' => [
            'message' => 'Este campo é obrigatório.',
        ],
        'email' => [
            'message' => 'Este campo deve ser um endereço de e-mail válido.',
        ],
        'min' => [
            'message' => 'Este campo deve ter pelo menos :min caracteres.',
        ],
        'max' => [
            'message' => 'Este campo não pode ter mais de :max caracteres.',
        ],
        'confirmed' => [
            'message' => 'A confirmação não corresponde.',
        ],
    ],
];
