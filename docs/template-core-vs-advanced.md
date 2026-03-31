# Template Engine: Core vs Avançado

Este documento classifica todas as diretivas, pipes e helpers do Spark Template Engine em três níveis: **core essencial**, **avançado** e **candidato à revisão**.

O objetivo é evitar que a DSL vire um segundo framework dentro do framework. A proposta central do Spark Template é ser próxima de HTML puro — expressiva no caso comum, simples de aprender.

---

## Nível 1a: Core da linguagem

A sintaxe-base da template engine. Um novo desenvolvedor aprende isso primeiro.

| Diretiva | O que faz |
|---|---|
| `@layout('name')` | Define qual layout envolve a view |
| `@title('texto')` | Define o `<title>` da página |
| `@content` | Marca onde o conteúdo da view é inserido no layout |
| `@partial('path')` | Inclui um fragmento reutilizável |
| `@if` / `@elseif` / `@else` / `@endif` | Condicional |
| `@foreach` / `@empty` / `@endforeach` | Loop com suporte a estado vazio |
| `{{ $var }}` | Saída com escape automático por contexto |

## Nível 1b: Core de formulários

Conveniências de domínio para formulários HTML. Fazem parte do core porque formulários são o caso mais comum em aplicações web, mas são separados da linguagem-base.

| Diretiva | O que faz |
|---|---|
| `@form(action, method)` | Form com CSRF automático |
| `@input(name)` | Campo com label, valor antigo e erro automáticos |
| `@submit('label')` | Botão de envio |

### Exemplo completo usando apenas core de linguagem + core de formulários

```spark
@title('Usuários')
@layout('main')

<h1>Usuários</h1>

@foreach($users as $user)
  <p>{{ $user->name }} — {{ $user->email }}</p>
@empty
  <p>Nenhum usuário encontrado.</p>
@endforeach

@form('/users', 'POST')
  @input('name', label: 'Nome')
  @input('email', label: 'E-mail')
  @submit('Salvar')
@endform
```

---

## Nível 2: Avançado

Diretivas válidas para casos reais, mas que requerem mais contexto. Use quando o core não for suficiente.

| Diretiva | Quando usar |
|---|---|
| `@component` / `@slot` / `@endslot` | Componentes reutilizáveis com slots nomeados |
| `@hasslot('name')` | Renderização condicional baseada em slot |
| `@stack('css'/'js')` | Assets empilhados a partir de views filhas |
| `@css()` / `@js()` | Registrar assets CSS/JS na stack do layout |
| `@once` / `@endonce` | Renderizar um bloco apenas uma vez por página |
| `@cache(key, ttl)` / `@endcache` | Cache de fragmento de template |
| `@lazy()` / `@endlazy` | Carregamento lazy client-side |
| `@for` / `@while` / `@repeat(n)` | Loops alternativos |
| `@first` / `@endfirst` / `@last` / `@endlast` | Condicionais dentro de loops |
| `@auth` / `@endauth` | Bloco visível apenas para usuários autenticados |
| `@role('name')` / `@can('ability')` | Blocos baseados em autorização |
| `@dev` / `@prod` | Blocos condicionais por ambiente |
| `{!! $var !!}` | Saída sem escape (HTML confiável) |
| `@php` / `@endphp` | PHP inline quando necessário |

---

## Nível 3: Candidatos à revisão futura

Estas diretivas e recursos existem, mas são candidatos a simplificação, remoção ou movimentação para um pacote separado em versões futuras. Não serão removidos sem ciclo de deprecação documentado.

| Recurso | Motivo da revisão |
|---|---|
| `@bodyClass()` | Raramente necessário; pode ser `{{ $bodyClass ?? '' }}` |
| `@active(rota)` | Acoplamento de lógica de UI à template; pode ser helper PHP |
| `@img()` / `@icon()` | Açúcar que replica o que HTML já faz |
| `@meta()` | Melhor gerenciado no layout diretamente |
| `@highlight` / `@endhighlight` | Feature de nicho; candidata a pacote opcional |
| Pipes `money`, `bytes`, `initials`, `markdown` | Candidatos a helpers PHP em vez de pipes de template |

> **Nota:** nenhum destes recursos será removido sem aviso prévio e documentação de substituição.

---

## Pipes disponíveis

Pipes transformam valores diretamente na saída: `{{ $valor | pipe }}`.

**Core (manter):**
`upper`, `lower`, `title`, `limit`, `slug`, `nl2br`, `default`

**Avançados (manter com revisão periódica):**
`date`, `number`, `count`, `markdown`

**Candidatos à revisão:**
`money`, `bytes`, `initials`

---

## Política de estabilidade da DSL

- **Core essencial**: API estável. Breaking changes apenas em versão major.
- **Avançado**: API beta. Pode mudar em versões minor com documentação de upgrade.
- **Candidatos à revisão**: Sem garantia de estabilidade. Serão marcados como deprecated antes de qualquer remoção.

---

## Referência cruzada

- [Views & Spark Templates](04-views.md) — guia prático
- [Contratos de inferência](inference-rules.md) — como a view espelho funciona
- [Fronteira do produto](00-product-scope.md) — onde a template engine se encaixa
