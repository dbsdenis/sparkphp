<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testMysqlGrammarCompilesCreateTableWithIndexesAndForeignKeys(): void
    {
        $blueprint = new Blueprint('users', 'create');
        $blueprint->id();
        $blueprint->string('email')->unique();
        $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
        $blueprint->timestamps();

        $sql = (new MySqlSchemaGrammar())->compile($blueprint);

        $this->assertStringContainsString('CREATE TABLE `users`', $sql[0]);
        $this->assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $sql[0]);
        $this->assertStringContainsString('CONSTRAINT `users_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE', $sql[0]);
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $sql[0]);
        $this->assertSame('CREATE UNIQUE INDEX `users_email_unique` ON `users` (`email`)', $sql[1]);
    }

    public function testPgsqlGrammarCompilesModernTypes(): void
    {
        $blueprint = new Blueprint('audit_logs', 'create');
        $blueprint->id();
        $blueprint->uuid('uuid')->unique();
        $blueprint->json('payload');
        $blueprint->boolean('processed')->default(false);

        $sql = (new PgsqlSchemaGrammar())->compile($blueprint);

        $this->assertStringContainsString('CREATE TABLE "audit_logs"', $sql[0]);
        $this->assertStringContainsString('"id" BIGSERIAL PRIMARY KEY', $sql[0]);
        $this->assertStringContainsString('"uuid" UUID NOT NULL', $sql[0]);
        $this->assertStringContainsString('"payload" JSONB NOT NULL', $sql[0]);
        $this->assertStringContainsString('"processed" BOOLEAN NOT NULL DEFAULT FALSE', $sql[0]);
        $this->assertSame('CREATE UNIQUE INDEX "audit_logs_uuid_unique" ON "audit_logs" ("uuid")', $sql[1]);
    }

    public function testSqliteGrammarCompilesAlterTableAddColumnAndIndex(): void
    {
        $blueprint = new Blueprint('users', 'table');
        $blueprint->string('nickname')->nullable()->index();

        $sql = (new SqliteSchemaGrammar())->compile($blueprint);

        $this->assertSame('ALTER TABLE "users" ADD COLUMN "nickname" VARCHAR(255)', $sql[0]);
        $this->assertSame('CREATE INDEX "users_nickname_index" ON "users" ("nickname")', $sql[1]);
    }

    public function testAddingForeignKeysOnExistingTablesFailsFast(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Adding foreign keys to existing tables');

        $blueprint = new Blueprint('users', 'table');
        $blueprint->foreignId('company_id')->constrained();

        (new SqliteSchemaGrammar())->compile($blueprint);
    }
}
