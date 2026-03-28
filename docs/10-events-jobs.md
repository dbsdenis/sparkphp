# Events & Jobs

## Events

O SparkPHP usa um EventEmitter file-based: o nome do arquivo em `app/events/` e o nome do evento. Sem registro, sem classes de evento.

### Criando um event handler

```bash
php spark make:event UserRegistered
```

Cria `app/events/UserRegistered.php`:

```php
// app/events/UserRegistered.php
<?php

// $data contem o que foi passado no dispatch
// Exemplo: ['user' => $user]

$user = $data['user'];

// Enviar email de boas-vindas
mail()
    ->to($user->email)
    ->subject('Bem-vindo!')
    ->view('emails.welcome', ['user' => $user])
    ->send();

// Registrar log
log_info("Novo usuario registrado: {$user->email}");
```

### Disparando eventos

```php
event('UserRegistered', ['user' => $user]);
```

O SparkPHP automaticamente localiza e executa `app/events/UserRegistered.php`, passando os dados como `$data`.

### Listeners in-memory

Para handlers temporarios (uteis em testes ou logica pontual):

```php
// Registrar listener
on('OrderPlaced', function ($data) {
    log_info("Pedido #{$data['order']->id} realizado");
});

// Remover listener
off('OrderPlaced', $callback);

// Disparar
event('OrderPlaced', ['order' => $order]);
```

### Eventos do Model

Models disparam eventos automaticamente em operacoes CRUD:

```php
class User extends Model
{
    protected static function booted(): void
    {
        static::created(function ($user) {
            event('UserRegistered', ['user' => $user]);
        });

        static::deleted(function ($user) {
            event('UserDeleted', ['user' => $user]);
        });
    }
}
```

### Exemplo: sistema de notificacoes

```php
// app/events/CommentCreated.php
<?php

$comment = $data['comment'];
$post    = Post::find($comment->post_id);
$author  = User::find($post->user_id);

// Notificar o autor do post
if ($author->id !== $comment->user_id) {
    db('notifications')->insert([
        'user_id' => $author->id,
        'type'    => 'new_comment',
        'data'    => json_encode([
            'post_id'    => $post->id,
            'comment_id' => $comment->id,
            'commenter'  => $comment->user_name,
        ]),
    ]);
}
```

---

## Jobs & Queues

Jobs permitem processar tarefas pesadas em background, sem travar a resposta HTTP.

### Configuracao

```env
# sync = executa imediatamente (dev)
# file = fila assincrona em storage/queue/ (production)
QUEUE=sync
```

### Criando um job

```bash
php spark make:job SendWelcomeEmail
```

```php
// app/jobs/SendWelcomeEmail.php
<?php

class SendWelcomeEmail
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        $user = User::find($this->data['user_id']);

        mail()
            ->to($user->email)
            ->subject('Bem-vindo ao ' . env('APP_NAME'))
            ->view('emails.welcome', ['user' => $user])
            ->send();
    }
}
```

### Despachando jobs

```php
// Executar na fila (ou imediatamente se QUEUE=sync)
dispatch(SendWelcomeEmail::class, ['user_id' => $user->id]);

// Executar com delay (apenas QUEUE=file)
dispatch_later(SendWelcomeEmail::class, ['user_id' => $user->id], 300); // 5 min
```

### Processando a fila

```bash
# Worker que processa jobs continuamente
php spark queue:work

# Com fila especifica
php spark queue:work --queue=emails

# Listar jobs pendentes
php spark queue:list

# Limpar todos os jobs da fila
php spark queue:clear
```

### Como a fila funciona (driver file)

1. `dispatch()` serializa o job e grava em `storage/queue/`
2. `queue:work` le os arquivos, instancia a classe e chama `handle()`
3. Se `handle()` lanca excecao, o job volta para a fila com backoff crescente
4. Apos max tentativas, o job vai para a fila de falhas

### Exemplo pratico: processamento de imagem

```php
// app/jobs/ProcessAvatar.php
<?php

class ProcessAvatar
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        $path = $this->data['path'];

        // Redimensionar imagem
        $img = imagecreatefromjpeg($path);
        $thumb = imagescale($img, 200, 200);
        imagejpeg($thumb, str_replace('.jpg', '_thumb.jpg', $path));

        imagedestroy($img);
        imagedestroy($thumb);
    }
}

// Na rota de upload
post(function () {
    $file = request()->file('avatar');
    $path = 'storage/uploads/' . $file['name'];
    move_uploaded_file($file['tmp_name'], $path);

    dispatch(ProcessAvatar::class, ['path' => $path]);

    return json(['message' => 'Upload recebido, processando...']);
});
```

### Combinando Events + Jobs

```php
// app/events/OrderPlaced.php
<?php

// Despachar jobs para processar em background
dispatch(SendOrderConfirmation::class, ['order_id' => $data['order']->id]);
dispatch(NotifyWarehouse::class, ['order_id' => $data['order']->id]);
dispatch(UpdateInventory::class, ['items' => $data['order']->items]);
```

## Proximo passo

→ [Mail](11-mail.md)
