# Middleware

Middlewares no SparkPHP sao **arquivos PHP simples** em `app/middleware/`. O nome do arquivo e o alias. Sem classes, sem registro.

## Middleware incluidos

| Arquivo          | Alias      | O que faz                                    |
|------------------|------------|----------------------------------------------|
| `auth.php`       | `auth`     | Redireciona para `/login` se nao autenticado |
| `csrf.php`       | `csrf`     | Verifica token CSRF em POST/PUT/PATCH/DELETE |
| `cors.php`       | `cors`     | Define headers CORS e trata preflight        |
| `throttle.php`   | `throttle` | Rate limiting por IP (default: 60 req/min)   |

## Criando um middleware

Crie um arquivo em `app/middleware/`:

```php
// app/middleware/admin.php
<?php

if (!auth() || !auth()->is_admin) {
    return json(['error' => 'Forbidden'], 403);
}

// Retornar null (ou nada) = continuar para a rota
```

**Regra**: se o middleware retorna algo (Response, array, string), a execucao **para ali**. Se retorna `null` ou nao retorna nada, a requisicao **segue para a rota**.

## Aplicando middlewares

### 1. Guard inline (por rota)

```php
// app/routes/admin/settings.php

get(fn() => ['settings' => true])->guard('auth', 'csrf');

post(fn() => ['saved' => true])->guard('auth', 'csrf', 'throttle:10');
```

### 2. Middleware por diretorio (automatico)

Coloque um arquivo `_middleware.php` dentro de um diretorio de rotas:

```php
// app/routes/admin/_middleware.php
<?php

// Todo arquivo em app/routes/admin/ passa por aqui
if (!auth() || !auth()->is_admin) {
    abort(403);
}
```

Todos os arquivos de rota dentro de `app/routes/admin/` (e subdiretorios) serao protegidos automaticamente.

### 3. Middleware global

Crie `app/routes/_middleware.php` (na raiz das rotas) para aplicar a **todas** as rotas:

```php
// app/routes/_middleware.php
<?php

// CORS em todas as rotas
require app_path('middleware/cors.php');
```

## Middleware com parametros

Use a sintaxe `alias:param1,param2` e acesse via `$params`:

```php
// app/middleware/throttle.php
<?php

$limit = (int) ($params[0] ?? 60);
$key   = 'throttle:' . ip();

$current = (int) (cache($key) ?? 0);

if ($current >= $limit) {
    return json(['error' => 'Too many requests. Please slow down.'], 429);
}

cache([$key => $current + 1], 60);
```

Uso:

```php
get(fn() => 'ok')->guard('throttle:30');   // 30 req/min
get(fn() => 'ok')->guard('throttle:120');  // 120 req/min
```

## Middleware com role

```php
// app/middleware/role.php
<?php

$role = $params[0] ?? null;

if (!auth() || auth()->role !== $role) {
    abort(403);
}
```

Uso:

```php
get(fn() => 'admin area')->guard('auth', 'role:admin');
get(fn() => 'editor area')->guard('auth', 'role:editor');
```

## Exemplo completo: CORS

```php
// app/middleware/cors.php
<?php

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '*';
$allowed = env('CORS_ORIGIN', '*');

header('Access-Control-Allow-Origin: ' . ($allowed === '*' ? '*' : $origin));
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
```

## Exemplo completo: Auth

```php
// app/middleware/auth.php
<?php

if (!auth()) {
    $request = app()->getContainer()->make(Request::class);

    if ($request->acceptsJson()) {
        return json(['error' => 'Unauthenticated.'], 401);
    }

    return redirect('/login');
}
```

Note como o mesmo middleware funciona para APIs (retorna JSON 401) e web (redireciona para login).

## Ordem de execucao

1. Middleware global (`app/routes/_middleware.php`)
2. Middleware de diretorio (`app/routes/admin/_middleware.php`)
3. Guards da rota (`.guard('auth', 'csrf')`)
4. Handler da rota

## Proximo passo

→ [Authentication](08-authentication.md)
