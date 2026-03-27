<?php

abstract class Seeder
{
    protected Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    abstract public function run(): void;

    public function call(string|array $seeders): void
    {
        foreach ((array) $seeders as $seeder) {
            if (!class_exists($seeder)) {
                throw new RuntimeException("Seeder not found: {$seeder}");
            }

            $instance = new $seeder($this->db);
            if (!$instance instanceof Seeder) {
                throw new RuntimeException("Seeder must extend Seeder: {$seeder}");
            }

            $instance->run();
        }
    }

    protected function schema(): Schema
    {
        return Schema::connection($this->db);
    }
}
