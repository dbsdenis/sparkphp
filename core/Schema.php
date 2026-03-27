<?php

class Schema
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public static function connection(?Database $db = null): static
    {
        return new static($db);
    }

    public static function create(string $table, callable $callback): void
    {
        static::connection()->runCreate($table, $callback);
    }

    public static function table(string $table, callable $callback): void
    {
        static::connection()->runTable($table, $callback);
    }

    public static function drop(string $table): void
    {
        static::connection()->runDrop($table, false);
    }

    public static function dropIfExists(string $table): void
    {
        static::connection()->runDrop($table, true);
    }

    public static function rename(string $from, string $to): void
    {
        static::connection()->runRename($from, $to);
    }

    public function createTable(string $table, callable $callback): void
    {
        $this->runCreate($table, $callback);
    }

    public function alterTable(string $table, callable $callback): void
    {
        $this->runTable($table, $callback);
    }

    public function dropTable(string $table): void
    {
        $this->runDrop($table, false);
    }

    public function dropTableIfExists(string $table): void
    {
        $this->runDrop($table, true);
    }

    public function renameTable(string $from, string $to): void
    {
        $this->runRename($from, $to);
    }

    public function getGrammar(): SchemaGrammar
    {
        return SchemaGrammar::forDriver($this->db->driver());
    }

    private function runCreate(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, 'create');
        $callback($blueprint);
        $this->execute($this->getGrammar()->compile($blueprint));
    }

    private function runTable(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, 'table');
        $callback($blueprint);
        $this->execute($this->getGrammar()->compile($blueprint));
    }

    private function runDrop(string $table, bool $ifExists): void
    {
        $this->execute($this->getGrammar()->compileDrop($table, $ifExists));
    }

    private function runRename(string $from, string $to): void
    {
        $this->execute($this->getGrammar()->compileRename($from, $to));
    }

    private function execute(array $statements): void
    {
        foreach ($statements as $statement) {
            $sql = trim($statement);
            if ($sql === '') {
                continue;
            }

            $this->db->statement($sql);
        }
    }
}

class Blueprint
{
    private string $table;
    private string $mode;
    /** @var array<int, ColumnDefinition> */
    private array $columns = [];

    public function __construct(string $table, string $mode)
    {
        $this->table = $table;
        $this->mode = $mode;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * @return array<int, ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn('id', $name)->primary()->autoIncrement();
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $name, [
            'precision' => $precision,
            'scale' => $scale,
        ]);
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    public function datetime(string $name): ColumnDefinition
    {
        return $this->addColumn('datetime', $name);
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn('timestamp', $name);
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    public function uuid(string $name): ColumnDefinition
    {
        return $this->addColumn('uuid', $name);
    }

    public function foreignId(string $name): ColumnDefinition
    {
        return $this->addColumn('foreignId', $name)->unsigned();
    }

    private function addColumn(string $type, string $name, array $attributes = []): ColumnDefinition
    {
        $column = new ColumnDefinition($this, $type, $name, $attributes);
        $this->columns[] = $column;
        return $column;
    }
}

class ColumnDefinition
{
    private Blueprint $blueprint;
    private string $type;
    private string $name;
    private array $attributes;
    private bool $nullable = false;
    private bool $unsigned = false;
    private bool $autoIncrement = false;
    private bool $primary = false;
    private bool $defaultSet = false;
    private mixed $default = null;
    private bool $index = false;
    private ?string $indexName = null;
    private bool $unique = false;
    private ?string $uniqueName = null;
    private ?string $references = null;
    private ?string $onTable = null;
    private ?string $onDelete = null;
    private ?string $onUpdate = null;

    public function __construct(Blueprint $blueprint, string $type, string $name, array $attributes = [])
    {
        $this->blueprint = $blueprint;
        $this->type = $type;
        $this->name = $name;
        $this->attributes = $attributes;
    }

    public function blueprint(): Blueprint
    {
        return $this->blueprint;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;
        return $this;
    }

    public function default(mixed $value): static
    {
        $this->defaultSet = true;
        $this->default = $value;
        return $this;
    }

    public function unique(?string $name = null): static
    {
        $this->unique = true;
        $this->uniqueName = $name;
        return $this;
    }

    public function index(?string $name = null): static
    {
        $this->index = true;
        $this->indexName = $name;
        return $this;
    }

    public function primary(bool $value = true): static
    {
        $this->primary = $value;
        return $this;
    }

    public function unsigned(bool $value = true): static
    {
        $this->unsigned = $value;
        return $this;
    }

    public function autoIncrement(bool $value = true): static
    {
        $this->autoIncrement = $value;
        return $this;
    }

    public function references(string $column): static
    {
        $this->references = $column;
        return $this;
    }

    public function on(string $table): static
    {
        $this->onTable = $table;
        return $this;
    }

    public function constrained(?string $table = null, string $column = 'id'): static
    {
        $table ??= $this->inferForeignTable();

        return $this->references($column)->on($table);
    }

    public function onDelete(string $action): static
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    public function onUpdate(string $action): static
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    public function cascadeOnDelete(): static
    {
        return $this->onDelete('cascade');
    }

    public function nullOnDelete(): static
    {
        return $this->onDelete('set null');
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function hasDefault(): bool
    {
        return $this->defaultSet;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    public function indexName(string $table): ?string
    {
        if (!$this->hasIndex()) {
            return null;
        }

        return $this->indexName ?: "{$table}_{$this->name}_index";
    }

    public function uniqueName(string $table): ?string
    {
        if (!$this->hasUnique()) {
            return null;
        }

        return $this->uniqueName ?: "{$table}_{$this->name}_unique";
    }

    public function foreignName(string $table): ?string
    {
        if (!$this->hasForeign()) {
            return null;
        }

        return "{$table}_{$this->name}_foreign";
    }

    public function hasIndex(): bool
    {
        return $this->index;
    }

    public function hasUnique(): bool
    {
        return $this->unique;
    }

    public function hasForeign(): bool
    {
        return $this->references !== null && $this->onTable !== null;
    }

    public function referencesColumn(): ?string
    {
        return $this->references;
    }

    public function onTable(): ?string
    {
        return $this->onTable;
    }

    public function onDeleteAction(): ?string
    {
        return $this->onDelete;
    }

    public function onUpdateAction(): ?string
    {
        return $this->onUpdate;
    }

    private function inferForeignTable(): string
    {
        $base = preg_replace('/_id$/', '', $this->name);
        if ($base === $this->name) {
            throw new RuntimeException("Cannot infer foreign table from column [{$this->name}]");
        }

        if (str_ends_with($base, 'y')) {
            return substr($base, 0, -1) . 'ies';
        }

        if (str_ends_with($base, 's')) {
            return $base;
        }

        return $base . 's';
    }
}

abstract class SchemaGrammar
{
    public static function forDriver(string $driver): static
    {
        return match ($driver) {
            'mysql' => new MySqlSchemaGrammar(),
            'pgsql' => new PgsqlSchemaGrammar(),
            'sqlite' => new SqliteSchemaGrammar(),
            default => throw new RuntimeException("Unsupported DB driver for schema: {$driver}"),
        };
    }

    /**
     * @return array<int, string>
     */
    public function compile(Blueprint $blueprint): array
    {
        return match ($blueprint->mode()) {
            'create' => $this->compileCreate($blueprint),
            'table' => $this->compileTable($blueprint),
            default => throw new RuntimeException("Unsupported blueprint mode: {$blueprint->mode()}"),
        };
    }

    /**
     * @return array<int, string>
     */
    public function compileDrop(string $table, bool $ifExists): array
    {
        $prefix = $ifExists ? 'DROP TABLE IF EXISTS ' : 'DROP TABLE ';
        return [$prefix . $this->wrapTable($table)];
    }

    /**
     * @return array<int, string>
     */
    public function compileRename(string $from, string $to): array
    {
        return ["ALTER TABLE {$this->wrapTable($from)} RENAME TO {$this->wrapTable($to)}"];
    }

    /**
     * @return array<int, string>
     */
    protected function compileCreate(Blueprint $blueprint): array
    {
        $columns = [];
        $constraints = [];
        $indexes = [];

        foreach ($blueprint->columns() as $column) {
            $columns[] = $this->compileColumn($column, true);

            if ($column->hasForeign()) {
                $constraints[] = $this->compileForeignConstraint($blueprint->table(), $column);
            }

            foreach ($this->compileIndexes($blueprint->table(), $column) as $statement) {
                $indexes[] = $statement;
            }
        }

        $body = implode(",\n    ", array_merge($columns, $constraints));
        $create = "CREATE TABLE {$this->wrapTable($blueprint->table())} (\n    {$body}\n)";
        $suffix = $this->compileCreateTableSuffix();

        if ($suffix !== '') {
            $create .= ' ' . $suffix;
        }

        return array_merge([$create], $indexes);
    }

    /**
     * @return array<int, string>
     */
    protected function compileTable(Blueprint $blueprint): array
    {
        $statements = [];

        foreach ($blueprint->columns() as $column) {
            if ($column->hasForeign()) {
                throw new RuntimeException('Adding foreign keys to existing tables is not supported in Database v2.');
            }

            if ($column->isPrimary()) {
                throw new RuntimeException('Adding primary keys through Schema::table() is not supported in Database v2.');
            }

            $definition = $this->compileColumn($column, false);
            $statements[] = "ALTER TABLE {$this->wrapTable($blueprint->table())} ADD COLUMN {$definition}";

            foreach ($this->compileIndexes($blueprint->table(), $column) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    protected function compileColumn(ColumnDefinition $column, bool $allowPrimary): string
    {
        if ($column->type() === 'id') {
            return $this->compileIdColumn($column);
        }

        $sql = $this->wrapColumn($column->name()) . ' ' . $this->columnType($column);

        if (!$column->isNullable()) {
            $sql .= ' NOT NULL';
        }

        if ($column->hasDefault()) {
            $sql .= ' DEFAULT ' . $this->quoteValue($column->defaultValue());
        }

        if ($allowPrimary && $column->isPrimary()) {
            $sql .= ' PRIMARY KEY';
        }

        return $sql;
    }

    protected function compileIdColumn(ColumnDefinition $column): string
    {
        return $this->wrapColumn($column->name()) . ' ' . $this->idColumnSql();
    }

    protected function compileForeignConstraint(string $table, ColumnDefinition $column): string
    {
        $sql = 'CONSTRAINT ' . $this->wrapIdentifier((string) $column->foreignName($table))
            . ' FOREIGN KEY (' . $this->wrapColumn($column->name()) . ')'
            . ' REFERENCES ' . $this->wrapTable((string) $column->onTable())
            . ' (' . $this->wrapColumn((string) $column->referencesColumn()) . ')';

        if ($column->onDeleteAction()) {
            $sql .= ' ON DELETE ' . $column->onDeleteAction();
        }

        if ($column->onUpdateAction()) {
            $sql .= ' ON UPDATE ' . $column->onUpdateAction();
        }

        return $sql;
    }

    /**
     * @return array<int, string>
     */
    protected function compileIndexes(string $table, ColumnDefinition $column): array
    {
        $statements = [];

        if ($column->hasUnique()) {
            $statements[] = 'CREATE UNIQUE INDEX '
                . $this->wrapIdentifier((string) $column->uniqueName($table))
                . ' ON ' . $this->wrapTable($table)
                . ' (' . $this->wrapColumn($column->name()) . ')';
        }

        if ($column->hasIndex()) {
            $statements[] = 'CREATE INDEX '
                . $this->wrapIdentifier((string) $column->indexName($table))
                . ' ON ' . $this->wrapTable($table)
                . ' (' . $this->wrapColumn($column->name()) . ')';
        }

        return $statements;
    }

    protected function compileCreateTableSuffix(): string
    {
        return '';
    }

    protected function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? $this->booleanTrue() : $this->booleanFalse();
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    protected function booleanTrue(): string
    {
        return '1';
    }

    protected function booleanFalse(): string
    {
        return '0';
    }

    protected function wrapTable(string $table): string
    {
        return $this->wrapIdentifier($table);
    }

    protected function wrapColumn(string $column): string
    {
        return $this->wrapIdentifier($column);
    }

    abstract protected function wrapIdentifier(string $value): string;

    abstract protected function idColumnSql(): string;

    abstract protected function columnType(ColumnDefinition $column): string;
}

class MySqlSchemaGrammar extends SchemaGrammar
{
    protected function wrapIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    protected function idColumnSql(): string
    {
        return 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function columnType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'string' => 'VARCHAR(' . ($column->attributes()['length'] ?? 255) . ')',
            'text' => 'TEXT',
            'boolean' => 'BOOLEAN',
            'integer' => 'INT' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'bigInteger' => 'BIGINT' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'decimal' => 'DECIMAL(' . ($column->attributes()['precision'] ?? 10) . ', ' . ($column->attributes()['scale'] ?? 2) . ')' . ($column->isUnsigned() ? ' UNSIGNED' : ''),
            'json' => 'JSON',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'uuid' => 'CHAR(36)',
            'foreignId' => 'BIGINT UNSIGNED',
            default => throw new RuntimeException("Unsupported column type [{$column->type()}] for mysql"),
        };
    }

    protected function compileCreateTableSuffix(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }
}

class PgsqlSchemaGrammar extends SchemaGrammar
{
    protected function wrapIdentifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    protected function idColumnSql(): string
    {
        return 'BIGSERIAL PRIMARY KEY';
    }

    protected function columnType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'string' => 'VARCHAR(' . ($column->attributes()['length'] ?? 255) . ')',
            'text' => 'TEXT',
            'boolean' => 'BOOLEAN',
            'integer' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'decimal' => 'NUMERIC(' . ($column->attributes()['precision'] ?? 10) . ', ' . ($column->attributes()['scale'] ?? 2) . ')',
            'json' => 'JSONB',
            'date' => 'DATE',
            'datetime' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'timestamp' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'uuid' => 'UUID',
            'foreignId' => 'BIGINT',
            default => throw new RuntimeException("Unsupported column type [{$column->type()}] for pgsql"),
        };
    }

    protected function booleanTrue(): string
    {
        return 'TRUE';
    }

    protected function booleanFalse(): string
    {
        return 'FALSE';
    }
}

class SqliteSchemaGrammar extends SchemaGrammar
{
    protected function wrapIdentifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    protected function idColumnSql(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function columnType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'string' => 'VARCHAR(' . ($column->attributes()['length'] ?? 255) . ')',
            'text' => 'TEXT',
            'boolean' => 'INTEGER',
            'integer' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'decimal' => 'NUMERIC(' . ($column->attributes()['precision'] ?? 10) . ', ' . ($column->attributes()['scale'] ?? 2) . ')',
            'json' => 'TEXT',
            'date' => 'TEXT',
            'datetime' => 'TEXT',
            'timestamp' => 'TEXT',
            'uuid' => 'TEXT',
            'foreignId' => 'INTEGER',
            default => throw new RuntimeException("Unsupported column type [{$column->type()}] for sqlite"),
        };
    }
}
