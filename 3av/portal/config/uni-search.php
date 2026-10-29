<?php

return [
    // Por quantos dias as conversas do chat ficam guardadas (depois são apagadas sozinhas)
    'historico_dias' => (int) env('CHAT_HISTORICO_DIAS', 7),

    // O chat é para dúvidas rápidas: a partir daqui aparece o aviso de conversa longa...
    'aviso_perguntas' => (int) env('CHAT_AVISO_PERGUNTAS', 15),
    // ...e aqui ela trava (o aluno continua numa conversa nova)
    'limite_perguntas' => (int) env('CHAT_LIMITE_PERGUNTAS', 20),

    // Documentos da secretaria (Regimento, Manual...): só PDF, até este tamanho em KB
    'documento_max_kb' => (int) env('DOCUMENTO_MAX_KB', 20480),
];
