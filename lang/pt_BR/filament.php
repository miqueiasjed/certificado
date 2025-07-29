<?php

return [
    'pages' => [
        'dashboard' => [
            'title' => 'Painel de Controle',
        ],
    ],
    'resources' => [
        'title' => 'Recursos',
        'pages' => [
            'create' => [
                'title' => 'Criar :label',
                'heading' => 'Criar :label',
                'actions' => [
                    'create' => [
                        'label' => 'Criar',
                    ],
                ],
            ],
            'edit' => [
                'title' => 'Editar :label',
                'heading' => 'Editar :label',
                'actions' => [
                    'save' => [
                        'label' => 'Salvar',
                    ],
                ],
            ],
            'list' => [
                'title' => ':label',
                'heading' => ':label',
                'actions' => [
                    'create' => [
                        'label' => 'Criar :label',
                    ],
                ],
            ],
        ],
    ],
    'actions' => [
        'create' => [
            'label' => 'Criar',
        ],
        'edit' => [
            'label' => 'Editar',
        ],
        'delete' => [
            'label' => 'Excluir',
        ],
        'save' => [
            'label' => 'Salvar',
        ],
        'cancel' => [
            'label' => 'Cancelar',
        ],
        'close' => [
            'label' => 'Fechar',
        ],
        'back' => [
            'label' => 'Voltar',
        ],
    ],
    'forms' => [
        'actions' => [
            'save' => [
                'label' => 'Salvar',
            ],
            'cancel' => [
                'label' => 'Cancelar',
            ],
        ],
    ],
    'tables' => [
        'actions' => [
            'edit' => [
                'label' => 'Editar',
            ],
            'delete' => [
                'label' => 'Excluir',
            ],
            'view' => [
                'label' => 'Visualizar',
            ],
        ],
        'columns' => [
            'actions' => [
                'label' => 'Ações',
            ],
        ],
    ],
    'modals' => [
        'actions' => [
            'confirm' => [
                'label' => 'Confirmar',
            ],
            'cancel' => [
                'label' => 'Cancelar',
            ],
        ],
    ],
    'notifications' => [
        'created' => [
            'title' => 'Criado com sucesso',
            'body' => ':label foi criado com sucesso.',
        ],
        'updated' => [
            'title' => 'Atualizado com sucesso',
            'body' => ':label foi atualizado com sucesso.',
        ],
        'deleted' => [
            'title' => 'Excluído com sucesso',
            'body' => ':label foi excluído com sucesso.',
        ],
    ],
    'navigation' => [
        'dashboard' => 'Painel de Controle',
        'resources' => 'Recursos',
    ],
];
