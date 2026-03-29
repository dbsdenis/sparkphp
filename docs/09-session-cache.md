# Session & Cache

## Session

Configuracao no `.env`:

```env
SESSION=file                # driver: file
SESSION_LIFETIME=7200       # tempo de vida em segundos (2 horas)
SESSION_SECURE=auto         # auto | true | false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=Lax       # Lax | Strict | None

# Lista separada por virgula: IP, CIDR ou *
TRUSTED_PROXIES=

# Endurecimento opcional do CSRF
CSRF_REQUIRE_ORIGIN=false
CSRF_TRUSTED_ORIGINS=
```

Defaults seguros do core:

- `SESSION_SECURE=auto` liga cookie seguro automaticamente em HTTPS
- `SESSION_HTTP_ONLY=true` protege contra leitura via JavaScript
- `SESSION_SAME_SITE=Lax` reduz CSRF cross-site sem quebrar fluxos comuns
- `SESSION_SAME_SITE=None` força cookie `secure=true`

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

O middleware `csrf.php` chama `preventRequestForgery()` / `verifyCsrf()` e:

1. Verifica se o metodo e mutavel (POST, PUT, PATCH, DELETE)
2. Compara `_csrf`, `X-CSRF-TOKEN` ou `X-XSRF-TOKEN` com o token da sessao
3. Valida `Origin` ou `Referer` quando o cliente envia esses headers
4. Retorna `419` em HTML ou JSON estruturado (`error` + `reason`) para API/AJAX

Os motivos retornados no JSON sao:

- `token_mismatch`
- `origin_mismatch`
- `missing_origin`

Exemplo:

```json
{
  "error": "Request forgery protection failed.",
  "status": 419,
  "code": "request_forgery",
  "reason": "token_mismatch"
}
```

### Proxies confiaveis

O Spark so confia em headers encaminhados (`X-Forwarded-*`) quando `TRUSTED_PROXIES` contem o IP/CIDR do proxy atual. Isso afeta:

- `ip()` e `request()->ip()`
- `request()->url()` e `request()->isSecure()`
- cookies seguros com `SESSION_SECURE=auto`
- comparacao de origem no CSRF

Sem `TRUSTED_PROXIES`, o framework ignora headers encaminhados para evitar spoofing.

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
    $cache->has('key');                   // bool
    $cache->touch('key', 600);            // estende TTL sem trocar o valor

    $cache->forget('key');                // remove chave
    $cache->flush();                      // limpa tudo

    $cache->increment('visits');          // +1
    $cache->increment('views', 5);        // +5
    $cache->decrement('stock');           // -1
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

### `touch()` e extensao de TTL

Use `touch()` quando quiser prolongar a vida de uma chave sem recalcular nem regravar
o valor da aplicacao:

```php
$cache->set('auth:token', 'abc123', 300);
$cache->touch('auth:token', 900);

cache_touch('auth:token', 900);
```

`expire()` continua existindo como alias por compatibilidade, mas a API preferida do
Spark passa a ser `touch()`.

### Stale-While-Revalidate

O Cache v2 inclui uma API de `stale-while-revalidate` para cenarios em que e melhor
servir um valor levemente antigo do que bloquear a resposta esperando recomputacao.

```php
$stats = cache_flexible('dashboard.stats', [30, 120], function () {
    return [
        'users' => User::count(),
        'sales' => Order::query()->sum('total'),
    ];
});
```

Nesse exemplo:

- por `30s` o valor e considerado fresco
- entre `31s` e `120s` o valor pode ser servido como stale
- durante a janela stale o Spark devolve o valor antigo e agenda um refresh no shutdown da requisicao
- depois de `120s`, se nao houver valor fresco, o callback roda sincronicamente

Tambem funciona via objeto:

```php
$cache->flexible('dashboard.stats', [30, 120], fn() => expensive_report());
```

### Tags

Tags permitem agrupar entradas relacionadas e invalida-las de forma previsivel sem
inventar prefixes manuais em cada chave.

```php
cache_tags(['users'])->set('list', User::all(), 300);
cache_tags(['users'])->set('summary', ['count' => User::count()], 300);

$users = cache_tags(['users'])->get('list');

cache_flush_tags('users'); // invalida o grupo inteiro
```

Via objeto:

```php
$usersCache = $cache->tags(['users']);

$usersCache->remember('list', 300, fn() => User::all());
$usersCache->touch('list', 600);
$usersCache->flush();
```

As tags tambem isolam namespaces iguais. Ou seja, `users:list` e `posts:list` podem
ter a mesma chave logica `list` sem colidir.

### Observabilidade no Spark Inspector

As operacoes de cache agora aparecem no Inspector com mais contexto:

- `hit` / `miss`
- `stale hit`
- `ttl` e `stale_ttl`
- `tags`
- `flush` por tag

Na aba Overview do Inspector voce tambem encontra:

- `Cache Ops`
- `Cache Hit Rate`
- `Stale Hits`

E na aba `Pipelines`:

- resumo do pipeline de cache da request
- hot keys com maior volume de operacoes
- correlacao com queue/request quando a resposta tambem enfileira jobs

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
