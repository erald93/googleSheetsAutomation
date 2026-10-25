<?php

namespace App\Services;

use App\Models\SheetTab;
use Illuminate\Support\Collection;
use RuntimeException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Throwable;

class SheetImportService
{
    private const TYPES = ['string', 'text', 'integer', 'decimal', 'date', 'datetime', 'boolean'];

    // columns the importer manages itself
    private const RESERVED_COLUMNS = ['id', 'sheet_tab_id', 'synced_at'];

    // tables a mapping must never point at
    private const PROTECTED_TABLES = [
        'migrations', 'users', 'sheet_sources', 'sheet_tabs', 'column_mappings', 'sync_logs',
        'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens',
    ];

    public function __construct(private GoogleSheetsReader $reader)
    {
    }

    public function import(SheetTab $tab): array
    {
        // Stage 1: load the config
        $tab->loadMissing(['source', 'columnMappings']);

        if (!$tab->is_active || !$tab->source?->is_active) {
            throw new RuntimeException('The spreadsheet source or tab is inactive.');
        }

        $mappings = $tab->columnMappings->where('is_active', true)->values();

        if ($mappings->isEmpty()) {
            throw new RuntimeException('No active column mappings are configured.');
        }

        // Stage 2: fetch
        [$headers, $rows] = $this->fetch($tab);

        if ($headers === []) {
            throw new RuntimeException("Tab '{$tab->tab_name}' is empty or could not be read; nothing was changed.");
        }

        // Stage 3: find each mapped column in the header row
        [$positions, $warnings] = $this->locateColumns($headers, $mappings);

        //dump($positions, $warnings);
        //die();

        // Stage 4 and 5: group by destination table and make sure the tables and columns exist
        $groups = $mappings->groupBy('destination_table');
        $this->prepareSchema($groups);
        
        // Stage 6: turn sheet rows into records, one per destination table
        [$records, $processed, $failed, $errors] = $this->transform($rows, $groups, $positions, $tab);

        // Stage 7: replace this tab's rows with the fresh ones
        $this->write($groups, $records, $tab);

        return [
            'rows_processed' => $processed,
            'rows_imported' => $processed - $failed,
            'rows_failed' => $failed,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @return array{0: array, 1: array} [headerRow, dataRows] */
    private function fetch(SheetTab $tab): array
    {
        $tabName = str_replace("'", "''", $tab->tab_name); // this checks for apostrophe ex:Bob's Sales

        $rows = $this->reader->read(
            $tab->source->spreadsheet_id,
            "'{$tabName}'!A:ZZ" //limited to zz, TODO
        );

        if (empty($rows)) {
            return [[], []];
        }

        $headers = array_shift($rows);

        return [$headers, $rows];
    }

    /**
     * Returns [positions, warnings]
     * positions: mapping id => column index in the sheet (or null when absent)
     */
    private function locateColumns(array $headers, Collection $mappings): array
    {
        // normalized header text => list of indexes where it appears
        $index = [];
        foreach ($headers as $i => $header) {
            $key = $this->normalize($header);
            if ($key !== '') {
                $index[$key][] = $i;
            }
        }

        $positions = [];
        $warnings = [];

        foreach ($mappings as $mapping) {
            $found = $index[$this->normalize($mapping->source_column)] ?? [];

            if (count($found) > 1) {
                throw new RuntimeException(
                    "Column '{$mapping->source_column}' appears more than once in the header row."
                );
            }

            if ($found === []) {
                if ($mapping->is_required) {
                    throw new RuntimeException(
                        "Required column '{$mapping->source_column}' was not found."
                    );
                }

                $warnings[] = "Column '{$mapping->source_column}' was not found; its values will be NULL.";
                $positions[$mapping->id] = null;
                continue;
            }

            $positions[$mapping->id] = $found[0];
        }

        return [$positions, $warnings];
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /**
     * Create missing destination tables and add missing columns (all nullable),
     * after validating names, types and duplicates.
     *
     * @param  Collection<string, Collection<int, ColumnMapping>>  $groups  [tableName => mappings]
     * @throws RuntimeException  On invalid or unsafe configuration.
     */
    private function prepareSchema(Collection $groups): void
    {
        foreach ($groups as $tableName => $tableMappings) {
            $this->validateIdentifier($tableName, 'table');

            if (in_array($tableName, self::PROTECTED_TABLES, true)) {
                throw new RuntimeException("Table '{$tableName}' is protected and cannot be a destination.");
            }

            $seen = [];
            foreach ($tableMappings as $mapping) {
                $column = $mapping->destination_column;

                $this->validateIdentifier($column, 'column');

                if (in_array($column, self::RESERVED_COLUMNS, true)) {
                    throw new RuntimeException("Column name '{$column}' is reserved.");
                }
                if (isset($seen[$column])) {
                    throw new RuntimeException("Duplicate destination column '{$column}' in table '{$tableName}'.");
                }
                if (!in_array(strtolower($mapping->destination_type), self::TYPES, true)) {
                    throw new RuntimeException("Unsupported type '{$mapping->destination_type}' for '{$column}'.");
                }
                $seen[$column] = true;
            }

            if (!Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) use ($tableMappings) {
                    $table->id();
                    $table->unsignedBigInteger('sheet_tab_id')->nullable()->index();
                    foreach ($tableMappings as $mapping) {
                        $this->addColumn($table, $mapping->destination_column, $mapping->destination_type);
                    }
                    $table->timestamp('synced_at')->nullable();
                });

                continue;
            }

            // The table exists, add anything that's missing
            if (!Schema::hasColumn($tableName, 'sheet_tab_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('sheet_tab_id')->nullable()->index();
                });
            }
            if (!Schema::hasColumn($tableName, 'synced_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->timestamp('synced_at')->nullable();
                });
            }

            foreach ($tableMappings as $mapping) {
                if (!Schema::hasColumn($tableName, $mapping->destination_column)) {
                    Schema::table($tableName, function (Blueprint $table) use ($mapping) {
                        $this->addColumn($table, $mapping->destination_column, $mapping->destination_type);
                    });
                }
            }
        }
    }

    private function addColumn(Blueprint $table, string $name, string $type): void
    {
        $column = match (strtolower($type)) {
            'integer'  => $table->bigInteger($name),
            'decimal'  => $table->decimal($name, 12, 2),
            'date'     => $table->date($name),
            'datetime' => $table->dateTime($name),
            'boolean'  => $table->boolean($name),
            'text'     => $table->text($name),
            default    => $table->string($name),
        };

        $column->nullable();
    }

    // make sure a table or column name from the configuration is safe to use in a database query
    private function validateIdentifier(string $name, string $what): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException("Invalid destination {$what} name: {$name}");
        }
    }

    private function transform(array $rows, Collection $groups, array $positions, SheetTab $tab): array
    {
        $records = [];   // [tableName => [record, record, ...]]
        $processed = 0;
        $failed = 0;
        $errors = [];
        $now = now();

        foreach ($rows as $i => $row) {
            if ($this->isBlank($row)) {
                continue;
            }

            $processed++;

            try {
                $built = [];

                foreach ($groups as $tableName => $tableMappings) {
                    $record = ['sheet_tab_id' => $tab->id, 'synced_at' => $now];

                    foreach ($tableMappings as $mapping) {
                        $position = $positions[$mapping->id];
                        $value = $position === null ? null : ($row[$position] ?? null);

                        if ($mapping->is_required && $this->isEmpty($value)) {
                            throw new RuntimeException("Required column '{$mapping->source_column}' is empty.");
                        }

                        $record[$mapping->destination_column] = $this->convert($value, $mapping->destination_type);
                    }

                    $built[$tableName] = $record;
                }

                // only keep the row once every table's record was built without an error
                foreach ($built as $tableName => $record) {
                    $records[$tableName][] = $record;
                }
            } catch (Throwable $e) {
                $failed++;

                if (count($errors) < 10) {
                    $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];   // +2: header is row 1
                }
            }
        }

        return [$records, $processed, $failed, $errors];
    }

    private function write(Collection $groups, array $records, SheetTab $tab): void
    {
        DB::transaction(function () use ($groups, $records, $tab) {
            foreach ($groups->keys() as $tableName) {
                DB::table($tableName)->where('sheet_tab_id', $tab->id)->delete();

                // small chunks
                foreach (array_chunk($records[$tableName] ?? [], 100) as $chunk) {
                    DB::table($tableName)->insert($chunk);
                }
            }
        });
    }

    private function convert(mixed $value, string $type): mixed
    {
        if ($this->isEmpty($value)) {
            return null;
        }

        $value = trim((string) $value);

        return match (strtolower($type)) {
            'integer'  => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (int) $value
                : throw new RuntimeException("Invalid integer: {$value}"),
            'decimal'  => is_numeric($value)
                ? (float) $value
                : throw new RuntimeException("Invalid number: {$value}"),
            'boolean'  => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new RuntimeException("Invalid boolean: {$value}"),
            'date'     => $this->toDate($value),
            'datetime' => $this->toDateTime($value),
            default    => $value,   // string, text
        };
    }

    private function toDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException("Invalid date (expected YYYY-MM-DD): {$value}");
        }

        return $value;
    }

    private function toDateTime(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new RuntimeException("Invalid datetime: {$value}");
        }
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (!$this->isEmpty($cell)) {
                return false;
            }
        }

        return true;
    }
}