<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthorizationTest extends TestCase
{
    public function testPolicyResolvesByConvention(): void
    {
        $this->assertInstanceOf(
            AuthorizationArticlePolicy::class,
            policy(new AuthorizationArticle())
        );
    }

    public function testCanUsesPolicyWithExplicitActor(): void
    {
        $article = new AuthorizationArticle();
        $article->setAttribute('user_id', 10);

        $this->assertTrue(can('view', $article, (object) ['id' => 10]));
        $this->assertFalse(can('view', $article, (object) ['id' => 20]));
    }

    public function testCanSupportsClassStringSubjects(): void
    {
        $this->assertTrue(can('create', AuthorizationArticle::class, (object) ['role' => 'admin']));
        $this->assertFalse(can('create', AuthorizationArticle::class, (object) ['role' => 'editor']));
    }
}

final class AuthorizationArticle extends Model
{
    protected string $table = 'articles';
    protected array $guarded = [];
    protected bool $timestamps = false;
}

final class AuthorizationArticlePolicy
{
    public function view(?object $user, AuthorizationArticle $article): bool
    {
        return (int) ($user->id ?? 0) === (int) ($article->user_id ?? 0);
    }

    public function create(?object $user, string $modelClass): bool
    {
        return ($user->role ?? null) === 'admin' && $modelClass === AuthorizationArticle::class;
    }
}
