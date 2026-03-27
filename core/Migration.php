<?php

abstract class Migration
{
    abstract public function up(): void;

    abstract public function down(): void;

    protected function db(): Database
    {
        return Database::getInstance();
    }

    protected function schema(): Schema
    {
        return Schema::connection($this->db());
    }
}
