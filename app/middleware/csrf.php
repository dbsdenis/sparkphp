<?php

// CSRF Middleware
// Verifica o token CSRF em requisições mutáveis (POST, PUT, PATCH, DELETE)

return preventRequestForgery();

// Se passou, continua (retornar null = seguir em frente)
