# Regras de Inferência e Previsibilidade

O SparkPHP infere bastante coisa automaticamente. Esta página documenta **o que o Spark infere**, **quando infere** e — igualmente importante — **o que o Spark nunca infere**.

Conhecer as duas listas é o que separa magia de previsibilidade.

---

## O que o Spark infere

### 1. View espelho por rota

Quando um handler retorna array ou objeto e o cliente aceita HTML, o Spark busca automaticamente a view correspondente ao caminho da rota.

```
GET /users         → app/views/users.spark
GET /users/1       → app/views/users/show.spark (se existir)
GET /admin/posts   → app/views/admin/posts.spark
```

**Precedência:**
1. View explícita via `view('nome', $data)` — sempre vence
2. View espelho pelo caminho da rota
3. Erro 500 se nenhuma das duas for encontrada

**Opt-out:** retornar um objeto `Response` diretamente desativa a inferência de view.

```php
// Sem inferência de view — retorna JSON explicitamente
get(fn() => Response::json(['users' => User::all()]));
```

---

### 2. Formato de resposta por `Accept`

```
Accept: text/html          → HTML (renderiza view ou retorna string)
Accept: application/json   → JSON
Sem Accept / */*            → HTML
```

**Precedência:**
1. Se o handler retornar `Response` diretamente → sem inferência
2. Se o handler retornar `string` → HTML, independente do Accept
3. Se o handler retornar `array` ou `object`:
   - JSON se `Accept: application/json`
   - HTML com view espelho caso contrário

---

### 3. Status de resposta por método e retorno

| Cenário | Status inferido |
|---|---|
| GET retorna `null` | 404 |
| POST/PUT retorna array ou objeto | 201 |
| Qualquer método retorna string | 200 |
| Qualquer método retorna array (GET, PATCH, DELETE) | 200 |

**Opt-out:** retornar `Response` com status explícito.

```php
// Status explícito — sem inferência
post(fn() => Response::json($data, 200));
```

---

### 4. Route model binding

Quando o type-hint de um parâmetro do handler é um Model e existe um parâmetro de URL com nome correspondente, o Spark resolve automaticamente via `findOrFail()`.

```php
// app/routes/users.[id].php
// O Spark injeta o User com id=$id — ou lança 404 se não encontrar
get(fn(User $user) => $user);
```

**Regra de correspondência:** o nome do parâmetro de URL deve coincidir com o nome do parâmetro do handler (case-insensitive) ou com o nome da classe em snake_case.

**Precedência:**
1. Se o type-hint for Model → route model binding
2. Se o type-hint for qualquer outra classe → DI do Container
3. Se não houver type-hint → valor bruto do parâmetro de URL

**Opt-out:** usar `string $id` ou `int $id` no type-hint para receber o valor bruto.

```php
// Recebe ID bruto — sem route model binding
get(fn(string $id) => db('users')->find($id));
```

---

### 5. Resolução de dependências por type-hint (Container)

Qualquer type-hint que não seja um tipo primitivo e não seja um Model com parâmetro de URL correspondente é resolvido pelo Container.

```php
get(fn(AuthService $auth, PaymentService $pay) => ...);
```

O Container instancia automaticamente as dependências usando o construtor da classe. Dependências aninhadas também são resolvidas recursivamente.

---

### 6. Fillable inferido do schema

Se o Model não declara `$fillable` explicitamente, o Spark infere as colunas preenchíveis a partir do schema da tabela — excluindo `id`, `created_at` e `updated_at`.

**Comportamento:** na primeira chamada que precisa do fillable, o Spark faz uma query de inspeção de schema e cacheia o resultado.

---

### 7. Relações inferidas de FKs

Para FKs com nomenclatura convencional (`{tabela}_id`), o Spark infere a relação `belongsTo` correspondente.

**Limitação:** FKs com nomes não-convencionais precisam ser declaradas explicitamente no Model. Ver [Matrix de Coerência](contract-matrix.md).

---

### 8. Middleware por localização de arquivo

```
app/middleware/auth.php           → middleware 'auth'
app/routes/_middleware.php        → aplicado a todas as rotas
app/routes/admin/_middleware.php  → aplicado a todas as rotas em /admin
```

O nome do arquivo (sem `.php`) é o identificador do middleware.

---

### 9. Events por filename

```
app/events/users.created.php  → disparado após User::create()
app/events/users.deleted.php  → disparado após User::delete()
```

O padrão `{tabela}.{ação}.php` é a convenção. Ações suportadas: `created`, `updated`, `deleted`.

---

## O que o Spark nunca infere

Estas são **garantias negativas** — se o Spark fizer qualquer uma dessas coisas, é um bug:

| Garantia | Descrição |
|---|---|
| Nunca executa handler sem rota correspondente | Rota sem arquivo = 404 |
| Nunca persiste dados sem chamada explícita | `save()`, `create()`, `update()` são sempre explícitos |
| Nunca escapa menos do que o necessário | `{{ }}` sempre escapa por contexto; `{!! !!}` é opt-in explícito |
| Nunca silencia erro de middleware | Falha de middleware produz status HTTP explícito |
| Nunca ativa o Inspector em produção por padrão | `APP_ENV=production` → Inspector off |
| Nunca infere relações com FKs não-convencionais | Declaração explícita obrigatória |
| Nunca resolve ambiguidade de DI silenciosamente | Container lança exceção clara quando não consegue resolver |

---

## Mensagens de erro esperadas

Quando inferência falha, o Spark deve produzir uma mensagem clara:

| Falha | Mensagem esperada |
|---|---|
| View espelho não encontrada | `View not found: users.spark (mirror for GET /users)` |
| Route model binding não encontra o registro | HTTP 404 com corpo JSON de erro |
| Container não consegue resolver dependência | Exceção com nome da classe e parâmetro |
| Middleware não encontrado | Exceção com nome do middleware esperado e caminho procurado |

---

## Referência cruzada

- [Fronteira do produto](00-product-scope.md)
- [Matriz de coerência docs vs runtime](contract-matrix.md)
- [Routing](02-routing.md)
- [Views](04-views.md)
