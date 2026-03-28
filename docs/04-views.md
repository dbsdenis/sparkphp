# Views & Spark Templates

O SparkPHP usa sua propria template engine com arquivos `.spark`. A sintaxe e inspirada no Blade, mas mais enxuta e com recursos extras como pipes e form helpers.

## Onde ficam as views

```
app/views/
├── layouts/
│   └── main.spark          ← layout padrao
├── partials/
│   ├── header.spark
│   └── footer.spark
├── errors/
│   ├── 404.spark
│   └── 500.spark
├── index.spark              ← view espelho de GET /
├── users.spark              ← view espelho de GET /users
└── users/
    └── show.spark           ← view espelho de GET /users/:id (se existir)
```

## Renderizando uma view

### Automatico (Smart Resolver)

Se a rota retorna um array e o request aceita HTML, o SparkPHP busca automaticamente a **view espelho** — uma view com o mesmo caminho da rota:

```php
// app/routes/users.php
// GET /users → renderiza app/views/users.spark com $users disponivel
get(fn() => ['users' => User::all()]);
```

### Explicito com `view()`

```php
get(fn() => view('users.index', ['users' => User::all()]));
```

Dot-notation mapeia para subdiretorios: `users.index` → `app/views/users/index.spark`.

---

## Layouts

### Definindo um layout

```html
<!-- app/views/layouts/main.spark -->
<!DOCTYPE html>
<html lang="{{ env('APP_LANG', 'pt-BR') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? env('APP_NAME') }}</title>
    @stack('css')
</head>
<body class="{{ $bodyClass ?? '' }}">

    @partial('header')

    <main>
        @content
    </main>

    @partial('footer')

    @stack('js')
</body>
</html>
```

- `@content` — onde o conteudo da view filha sera inserido
- `@stack('css')` / `@stack('js')` — placeholders para CSS e JS empilhados
- `@partial('header')` — inclui `app/views/partials/header.spark`

### Usando o layout em uma view

O layout `main` e aplicado **automaticamente** a todas as views. Para trocar:

```
@layout('admin')
```

Isso usa `app/views/layouts/admin.spark`.

### Passando dados para o layout

```
@title('Dashboard')
@bodyClass('page-dashboard')
```

Essas diretivas definem variaveis que o layout pode usar (`$title`, `$bodyClass`).

---

## Output (interpolacao)

### Escapado (seguro contra XSS)

```
{{ $name }}
{{ $user->name }}
{{ config('app.name') }}
{{ auth()->name ?? 'Visitante' }}
```

Equivale a `htmlspecialchars($value)`.

### Nao-escapado (raw)

```
{!! $htmlContent !!}
{!! markdown($post->body) !!}
```

Use apenas quando voce confia no conteudo.

---

## Pipes (transformacoes encadeadas)

Pipes permitem transformar valores inline, sem helpers aninhados:

```
{{ $name | upper }}                     → JOAO
{{ $name | lower }}                     → joao
{{ $name | title }}                     → Joao Da Silva
{{ $name | slug }}                      → joao-da-silva
{{ $text | limit:100 }}                 → Trunca em 100 chars + "..."
{{ $text | words:20 }}                  → Trunca em 20 palavras
{{ $date | date:'d/m/Y' }}             → 28/03/2026
{{ $date | relative }}                  → ha 3 minutos
{{ $price | number:2 }}                → 1.234,56
{{ $price | currency:'R$' }}           → R$ 1.234,56
{{ $text | nl2br }}                    → Quebras de linha → <br>
{{ $text | markdown }}                 → Markdown → HTML
{{ $text | e }}                         → htmlspecialchars
{{ $text | strip }}                     → strip_tags
{{ $json | json }}                      → JSON pretty-print
{{ $list | count }}                     → count($list)
{{ $list | first }}                     → primeiro elemento
{{ $list | last }}                      → ultimo elemento
{{ $list | join:', ' }}                → implode(', ', $list)
{{ $list | reverse }}                  → array_reverse
{{ $list | sort }}                      → sort natural
{{ $value | default:'N/A' }}           → fallback se vazio
{{ $value | dump }}                     → var_export (debug)
```

### Encadeando pipes

```
{{ $user->name | lower | slug }}       → joao-da-silva
{{ $post->body | strip | limit:200 }}  → texto puro truncado
{{ $order->total | number:2 | currency:'R$' }}
```

---

## Condicionais

```
@if($user->isAdmin())
    <span class="badge">Admin</span>
@elseif($user->isMod())
    <span class="badge">Moderador</span>
@else
    <span class="badge">Membro</span>
@endif
```

### Atalhos

```
@isset($user)
    Ola, {{ $user->name }}
@endisset

@empty($notifications)
    Sem notificacoes.
@endempty

@unless($user->verified)
    Verifique seu e-mail.
@endunless
```

### Condicional de ambiente

```
@dev
    <div class="debug-bar">APP_ENV=dev | PHP {{ phpversion() }}</div>
@enddev

@prod
    <script src="/analytics.js"></script>
@endprod
```

### Condicional de autenticacao

```
@auth
    Bem-vindo, {{ auth()->name }}!
    <a href="/logout">Sair</a>
@endauth

@guest
    <a href="/login">Entrar</a>
@endguest
```

### Condicional de role/permissao

```
@role('admin')
    <a href="/admin">Painel Admin</a>
@endrole

@can('edit-post')
    <button>Editar</button>
@endcan
```

---

## Loops

### @foreach

```
@foreach($users as $user)
    <p>{{ $user->name }}</p>
@endforeach
```

### Variaveis de loop

Dentro de `@foreach`, voce tem acesso a variaveis especiais:

```
@foreach($items as $item)
    @first
        <h2>Lista de itens:</h2>
    @endfirst

    <div class="{{ $loop->even ? 'bg-gray' : '' }}">
        {{ $loop->index }}. {{ $item->name }}
    </div>

    @last
        <p>Total: {{ $loop->count }} itens</p>
    @endlast
@endforeach
```

| Variavel          | Descricao                       |
|-------------------|---------------------------------|
| `$loop->index`    | Indice atual (0-based)          |
| `$loop->iteration`| Iteracao atual (1-based)        |
| `$loop->first`    | Primeiro item? (bool)           |
| `$loop->last`     | Ultimo item? (bool)             |
| `$loop->even`     | Iteracao par? (bool)            |
| `$loop->odd`      | Iteracao impar? (bool)          |
| `$loop->count`    | Total de itens                  |
| `$loop->remaining`| Itens restantes                 |

### @forelse (loop com fallback vazio)

```
@forelse($posts as $post)
    <article>{{ $post->title }}</article>
@empty
    <p>Nenhum post encontrado.</p>
@endforelse
```

### @for e @while

```
@for($i = 0; $i < 10; $i++)
    <span>{{ $i }}</span>
@endfor

@while($condition)
    ...
@endwhile
```

---

## Formularios

O Spark Template tem helpers de formulario que geram HTML completo com CSRF automatico:

### @form

```
@form('/users', 'POST')
    @input('name', 'text', 'Seu nome')
    @input('email', 'email', 'E-mail')
    @submit('Cadastrar')
@endform
```

Gera:

```html
<form method="POST" action="/users">
    <input type="hidden" name="_csrf" value="abc123...">
    <div class="form-group">
        <label for="name">Seu nome</label>
        <input type="text" id="name" name="name" value="">
    </div>
    <div class="form-group">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="">
    </div>
    <button type="submit">Cadastrar</button>
</form>
```

### Method override automatico

```
@form('/users/42', 'PUT')
    ...
@endform
```

Gera `method="POST"` com `<input type="hidden" name="_method" value="PUT">` automaticamente.

### Outros helpers de formulario

```
@select('country', [
    'br' => 'Brasil',
    'us' => 'EUA',
    'pt' => 'Portugal'
])

@checkbox('terms', 'Aceito os termos')

@radio('plan', 'basic', 'Plano Basico')
@radio('plan', 'pro', 'Plano Pro')

@file('avatar')

@hidden('user_id', $user->id)

@textarea('bio', 'Sua biografia', 5)
```

### Valores antigos (old input)

Apos falha de validacao, os campos sao preenchidos automaticamente com `old()`:

```
@input('name', 'text', 'Nome', old('name'))
```

---

## Partials

Inclua fragmentos reutilizaveis:

```
@partial('header')
@partial('sidebar')
@partial('footer')
```

Busca em `app/views/partials/header.spark`, etc.

### Passando dados para partials

```
@partial('user-card', ['user' => $user, 'showAvatar' => true])
```

---

## Componentes e Slots

### Definindo um componente

```html
<!-- app/views/partials/alert.spark -->
<div class="alert alert-{{ $type ?? 'info' }}">
    @hasslot('icon')
        <span class="alert-icon">@slot('icon')</span>
    @endhasslot

    <div class="alert-body">
        @slot('default')
    </div>
</div>
```

### Usando o componente

```
@component('alert', ['type' => 'success'])
    @slot('icon')
        ✓
    @endslot

    Operacao realizada com sucesso!
@endcomponent
```

---

## Stacks (CSS e JS)

### Na view filha — empilhar assets

```
@css('/css/datepicker.css')
@js('/js/datepicker.js')
```

### No layout — renderizar os stacks

```html
<head>
    @stack('css')
</head>
<body>
    ...
    @stack('js')
</body>
```

`@css` e `@js` geram tags `<link>` e `<script>` automaticamente.

---

## Helpers diversos

### @cache

Cacheia um bloco HTML por tempo determinado (segundos):

```
@cache('sidebar-popular', 300)
    @foreach(Post::popular() as $post)
        <a href="/posts/{{ $post->slug }}">{{ $post->title }}</a>
    @endforeach
@endcache
```

### @once

Renderiza o bloco apenas uma vez, mesmo que a view seja incluida multiplas vezes:

```
@once
    <script src="/js/tooltip.js"></script>
@endonce
```

### @json

Inline JSON seguro para JavaScript:

```
<script>
    const config = @json(['api' => '/api', 'debug' => true]);
</script>
```

### @active (link ativo)

```
<a href="/dashboard" class="@active('/dashboard')">Dashboard</a>
```

Gera `class="active"` quando a URL atual coincide.

### @img

```
@img('/images/logo.png', 'Logo', 'w-32')
```

Gera: `<img src="/images/logo.png" alt="Logo" class="w-32">`

### @meta

```
@meta('description', 'SparkPHP - Write what matters.')
@meta('og:title', $post->title)
```

### @paginate

```
@paginate($users)
```

Gera links de paginacao automaticamente baseado nos metadados retornados pelo QueryBuilder.

---

## Compilacao e cache

- Em `dev`: views sao recompiladas automaticamente quando modificadas
- Em `production`: views sao compiladas uma vez e cacheadas em `storage/cache/views/`

```bash
# Pre-compilar todas as views
php spark views:cache

# Limpar cache de views
php spark views:clear
```

---

## Temas (dark/light mode)

O layout padrao do SparkPHP inclui suporte a temas via `data-theme` no HTML e CSS custom properties:

```css
:root {
    --brand: #ff9f2f;
    --text-primary: #f6f7fb;
    --surface-1: rgba(16, 19, 28, 0.9);
    /* ... */
}

:root[data-theme='light'] {
    --text-primary: #111827;
    --surface-1: rgba(255, 255, 255, 0.84);
    /* ... */
}
```

O tema e detectado automaticamente via `prefers-color-scheme` e salvo em `localStorage`.

## Proximo passo

→ [Database](05-database.md)
