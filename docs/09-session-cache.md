# Session & Cache

## Session

Configuracao no `.env`:

```env
SESSION=file                # driver: file
SESSION_LIFETIME=7200       # tempo de vida em segundos (2 horas)
SESSION_SECURE=false        # true = cookie so via HTTPS
```

### Leitura e escrita

```php
// Ler
$locale = session('locale');                  // valor ou null
$locale = session('locale', 'pt-BR');         // com fallback

// Escrever
session(['locale' => 'en']);
session(['theme' => 'dark', 'sidebar' => 'collapsed']);

// Via objeto Session
get(function (Session $session) {
    $session->get('locale');
    $session->get('locale', 'pt-BR');

    $session->set('locale', 'en');
    $session->put(['a' => 1, 'b' => 2]);

    $session->has('locale');     // bool
    $session->forget('locale');  // remove chave
    $session->flush();           // limpa tudo
});
```

### Flash messages

Dados flash vivem por **uma unica requisicao** — ideais para mensagens de feedback:

```php
// Setar flash
flash('success', 'Perfil atualizado!');
flash('error', 'Algo deu errado.');

// Em outro lugar (rota/view), na requisicao seguinte:
$msg = flash('success');  // valor flash disponivel nesta requisicao
```

### Na view

```
@if(flash('success'))
    <div class="alert alert-success">{{ flash('success') }}</div>
@endif

@if(flash('error'))
    <div class="alert alert-danger">{{ flash('error') }}</div>
@endif
```

### Regenerar sessao

Util apos login para prevenir session fixation:

```php
session_regenerate();
```

---

## CSRF Protection

O SparkPHP gera automaticamente um token CSRF por sessao.

### No formulario

```
@form('/users', 'POST')
    @input('name', 'text', 'Nome')
    @submit('Salvar')
@endform
```

O `@form` ja inclui o campo `_csrf` automaticamente. Se preferir manualmente:

```html
<form method="POST" action="/users">
    <input type="hidden" name="_csrf" value="{{ csrf() }}">
    ...
</form>
```

### Para AJAX/fetch

```javascript
fetch('/api/users', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify({ name: 'Ana' }),
});
```

Adicione no layout:

```html
<meta name="csrf-token" content="{{ csrf() }}">
```

### Aplicando middleware CSRF

```php
post(fn() => 'ok')->guard('csrf');
```

Ou organizando rotas em diretorios como `app/routes/[csrf]/users.php`.

### Como funciona por baixo

O middleware `csrf.php` chama `verifyCsrf()` que:

1. Verifica se o metodo e mutavel (POST, PUT, PATCH, DELETE)
2. Compara `$_POST['_csrf']` ou header `X-CSRF-TOKEN` com o token da sessao
3. Se nao bate, retorna 419 (Token Expired)

---

## Cache

Configuracao no `.env`:

```env
CACHE=file      # file (persiste em disco) ou memory (so na requisicao)
```

### Leitura e escrita

```php
// Ler
$value = cache('key');                    // valor ou null
$value = cache('key', 'default');         // com fallback

// Escrever (com TTL em segundos)
cache(['key' => 'value'], 300);           // 5 minutos
cache(['users_count' => 42], 3600);       // 1 hora

// Sem TTL = cache permanente (ate flush)
cache(['config' => $data]);
```

### Via objeto Cache

```php
get(function (Cache $cache) {
    $cache->get('key');
    $cache->get('key', 'default');

    $cache->set('key', 'value', 300);     // 300 segundos
    $cache->has('key');                    // bool

    $cache->forget('key');                 // remove chave
    $cache->flush();                       // limpa tudo

    $cache->increment('visits');           // +1
    $cache->increment('views', 5);         // +5
    $cache->decrement('stock');            // -1
});
```

### Remember (cache com fallback lazy)

```php
$users = cache_remember('all_users', 600, function () {
    return User::all();
});

// Se 'all_users' existe no cache, retorna direto.
// Se nao, executa o callback, salva no cache por 600s, e retorna.
```

### Cache de blocos na view

```
@cache('popular-posts', 300)
    @foreach(Post::popular()->limit(10)->get() as $post)
        <a href="/posts/{{ $post->slug }}">{{ $post->title }}</a>
    @endforeach
@endcache
```

O HTML renderizado e cacheado por 5 minutos.

### Limpando cache

```bash
php spark cache:clear           # Limpa todo o cache
php spark views:clear           # Limpa cache de views compiladas
php spark routes:clear          # Limpa cache de rotas
```

Ou no PHP:

```php
cache_flush();
```

## Proximo passo

→ [Events & Jobs](10-events-jobs.md)
