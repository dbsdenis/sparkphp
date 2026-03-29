# Mail

O SparkPHP inclui um mailer SMTP embutido — sem dependencias externas.

## Configuracao

```env
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls         # tls | ssl
MAIL_USER=seu@gmail.com
MAIL_PASS=sua-app-password
MAIL_FROM=seu@gmail.com
MAIL_FROM_NAME="${APP_NAME}"
```

## Enviando e-mails

### API fluente

```php
mailer()
    ->to('ana@example.com')
    ->subject('Bem-vindo!')
    ->html('<h1>Ola, Ana!</h1><p>Obrigado por se cadastrar.</p>')
    ->send();
```

### Com view Spark

```php
mailer()
    ->to('ana@example.com')
    ->subject('Bem-vindo!')
    ->view('emails.welcome', ['user' => $user])
    ->send();
```

A view `emails.welcome` busca `app/views/emails/welcome.spark`:

```html
<!-- app/views/emails/welcome.spark -->
<h1>Ola, {{ $user->name }}!</h1>
<p>Obrigado por se cadastrar no {{ env('APP_NAME') }}.</p>
<p>
    <a href="{{ env('APP_URL') }}/dashboard">
        Acessar seu painel
    </a>
</p>
```

### Texto plano

```php
mailer()
    ->to('ana@example.com')
    ->subject('Confirmacao')
    ->text('Seu codigo de confirmacao e: 123456')
    ->send();
```

### Destinatarios multiplos

```php
mailer()
    ->to('ana@example.com')
    ->to('bob@example.com')
    ->cc('gerente@example.com')
    ->bcc('arquivo@example.com')
    ->replyTo('suporte@example.com')
    ->subject('Relatorio mensal')
    ->view('emails.report', ['data' => $report])
    ->send();
```

### Remetente customizado

```php
mailer()
    ->from('noreply@example.com', 'Sistema de Alertas')
    ->to('admin@example.com')
    ->subject('Alerta de seguranca')
    ->html('<p>Atividade suspeita detectada.</p>')
    ->send();
```

### Anexos

```php
mailer()
    ->to('cliente@example.com')
    ->subject('Sua nota fiscal')
    ->view('emails.invoice', ['order' => $order])
    ->attach('/storage/invoices/NF-001.pdf')
    ->attach('/storage/invoices/boleto.pdf')
    ->send();
```

## E-mail assincrono (via Jobs)

Para nao travar a resposta HTTP, envie e-mails via job:

```php
// app/jobs/SendEmailJob.php
<?php

class SendEmailJob
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        mailer()
            ->to($this->data['to'])
            ->subject($this->data['subject'])
            ->view($this->data['view'], $this->data['vars'] ?? [])
            ->send();
    }
}
```

```php
// Na rota
dispatch(SendEmailJob::class, [
    'to'      => $user->email,
    'subject' => 'Bem-vindo!',
    'view'    => 'emails.welcome',
    'vars'    => ['user' => $user],
]);
```

## Exemplo completo: recuperacao de senha

```php
// app/routes/forgot-password.php

post(function () {
    $data = validate(['email' => 'required|email|exists:users,email']);

    $user  = User::where('email', $data['email'])->first();
    $token = bin2hex(random_bytes(32));

    db('password_resets')->updateOrCreate(
        ['email' => $user->email],
        ['token' => $token, 'created_at' => date('Y-m-d H:i:s')]
    );

    mailer()
        ->to($user->email)
        ->subject('Redefinir sua senha')
        ->view('emails.reset-password', [
            'user' => $user,
            'url'  => env('APP_URL') . '/reset-password?token=' . $token,
        ])
        ->send();

    flash('success', 'E-mail de recuperacao enviado!');
    return back();
});
```

## Proximo passo

→ [Helpers](12-helpers.md)
