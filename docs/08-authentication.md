# Authentication

O SparkPHP fornece helpers simples para autenticacao baseada em sessao.

## Helpers globais

```php
auth();              // Retorna o usuario logado (Model) ou null
login($user);        // Loga o usuario (salva na sessao)
logout();            // Desloga o usuario (limpa a sessao)
```

## Login

```php
// app/routes/login.php

// Exibir formulario
get(fn() => view('login'));

// Processar login
post(function () {
    $data = validate([
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    $user = User::where('email', $data['email'])->first();

    if (!$user || !password_verify($data['password'], $user->password)) {
        flash('error', 'Credenciais invalidas.');
        return back();
    }

    login($user);

    return redirect('/dashboard');
});
```

## Logout

```php
// app/routes/logout.php

post(function () {
    logout();
    return redirect('/');
});
```

## Verificando autenticacao

### No PHP (rotas, middleware)

```php
if (auth()) {
    $name = auth()->name;
    $id   = auth()->id;
}

if (!auth()) {
    return redirect('/login');
}
```

### Na view (Spark Template)

```
@auth
    <p>Ola, {{ auth()->name }}!</p>
    <form method="POST" action="/logout">
        <input type="hidden" name="_csrf" value="{{ csrf() }}">
        <button type="submit">Sair</button>
    </form>
@endauth

@guest
    <a href="/login">Entrar</a>
    <a href="/register">Cadastrar</a>
@endguest
```

## Protegendo rotas

### Com middleware

```php
// Guard inline
get(fn() => ['profile' => auth()])->guard('auth');

// Diretorio inteiro via convencao file-based
// app/routes/[auth]/admin/index.php
get(fn() => 'admin area');
```

### Com role

```
@role('admin')
    <a href="/admin">Painel Admin</a>
@endrole
```

```php
// Na rota
get(fn() => 'admin only')->guard('auth', 'role:admin');
```

## Registro de usuario

```php
// app/routes/register.php

get(fn() => view('register'));

post(function () {
    $data = validate([
        'name'     => 'required|string|min:2|max:100',
        'email'    => 'required|email|unique:users,email',
        'password' => 'required|string|min:8|confirmed',
    ]);

    $user = User::create([
        'name'     => $data['name'],
        'email'    => $data['email'],
        'password' => password_hash($data['password'], PASSWORD_DEFAULT),
    ]);

    login($user);

    return redirect('/dashboard');
});
```

## Exemplo completo: Dashboard protegido

```php
// app/routes/dashboard.php

get(function () {
    return [
        'user'  => auth(),
        'stats' => [
            'posts'    => Post::where('user_id', auth()->id)->count(),
            'comments' => Comment::where('user_id', auth()->id)->count(),
        ],
    ];
})->guard('auth');
```

```
<!-- app/views/dashboard.spark -->
@title('Dashboard')

<h1>Ola, {{ auth()->name }}</h1>

<div class="stats">
    <div class="stat">
        <span>{{ $stats['posts'] }}</span>
        <label>Posts</label>
    </div>
    <div class="stat">
        <span>{{ $stats['comments'] }}</span>
        <label>Comentarios</label>
    </div>
</div>
```

## Proximo passo

→ [Session & Cache](09-session-cache.md)
