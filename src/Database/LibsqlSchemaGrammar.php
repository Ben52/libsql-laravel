<?php

declare(strict_types=1);

namespace Libsql\Laravel\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Fluent;

class LibsqlSchemaGrammar extends SQLiteGrammar
{
    /**
     * Compile a vector index command into libSQL DDL.
     *
     * Laravel 13 added a native Blueprint::vectorIndex() method whose command is
     * resolved through Grammar::compileVectorIndex(), which throws by default.
     * libSQL exposes vector indexes via the libsql_vector_idx() function, so we
     * emit raw (unwrapped) identifiers to match the index name referenced by the
     * nearest() query macro and to avoid this grammar's wrap() turning the table
     * name into a string literal.
     */
    public function compileVectorIndex(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'create index %s on %s (libsql_vector_idx(%s))',
            $command->index,
            $blueprint->getTable(),
            implode(', ', (array) $command->columns)
        );
    }

    public function compileDropAllIndexes(): string
    {
        return "SELECT 'DROP INDEX IF EXISTS \"' || name || '\";' FROM sqlite_schema WHERE type = 'index' AND name NOT LIKE 'sqlite_%'";
    }

    public function compileDropAllTables($schema = null): string
    {
        return "SELECT 'DROP TABLE IF EXISTS \"' || name || '\";' FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
    }

    public function compileDropAllTriggers(): string
    {
        return "SELECT 'DROP TRIGGER IF EXISTS \"' || name || '\";' FROM sqlite_schema WHERE type = 'trigger' AND name NOT LIKE 'sqlite_%'";
    }

    public function compileDropAllViews($schema = null): string
    {
        return "SELECT 'DROP VIEW IF EXISTS \"' || name || '\";' FROM sqlite_schema WHERE type = 'view'";
    }

    #[\Override]
    public function wrap($value, $prefixAlias = false): string
    {
        return str_replace('"', '\'', parent::wrap($value));
    }

    public function typeVector(Fluent $column): string
    {
        if (isset($column->dimensions) && $column->dimensions !== '') {
            return "F32_BLOB({$column->dimensions})";
        }

        throw new \RuntimeException('Dimension must be set for vector embedding');
    }
}
