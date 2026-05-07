<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver !== 'sqlite') {
            Schema::table('working_memory_inputs', function (Blueprint $table): void {
                $table->uuid('source_version_id')->nullable()->after('thought_id');
            });

            Schema::table('working_memory_inputs', function (Blueprint $table): void {
                $table->foreign('source_version_id', 'working_memory_inputs_source_version_fk')
                    ->references('id')
                    ->on('working_memory_versions')
                    ->nullOnDelete();

                $table->unique(
                    ['working_memory_version_id', 'source_version_id'],
                    'working_memory_inputs_version_source_unique'
                );
            });
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE working_memory_inputs ALTER COLUMN thought_id DROP NOT NULL');
            DB::statement(
                'ALTER TABLE working_memory_inputs ADD CONSTRAINT working_memory_inputs_input_target_chk '
                .'CHECK ((thought_id IS NOT NULL)::int + (source_version_id IS NOT NULL)::int = 1)'
            );
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE working_memory_inputs MODIFY thought_id CHAR(36) NULL');
            DB::statement(
                'ALTER TABLE working_memory_inputs ADD CONSTRAINT working_memory_inputs_input_target_chk '
                .'CHECK ((thought_id IS NOT NULL) <> (source_version_id IS NOT NULL))'
            );
        } elseif ($driver === 'sqlite') {
            // SQLite cannot alter NOT NULL or add CHECK in place; rebuild the table.
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('CREATE TABLE working_memory_inputs__new (
                id TEXT PRIMARY KEY NOT NULL,
                working_memory_version_id TEXT NOT NULL,
                thought_id TEXT NULL,
                source_version_id TEXT NULL,
                contribution_type VARCHAR(32) NOT NULL,
                weight NUMERIC(5,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (working_memory_version_id) REFERENCES working_memory_versions(id) ON DELETE CASCADE,
                FOREIGN KEY (thought_id) REFERENCES thoughts(id) ON DELETE CASCADE,
                FOREIGN KEY (source_version_id) REFERENCES working_memory_versions(id) ON DELETE SET NULL,
                CHECK ((thought_id IS NOT NULL) <> (source_version_id IS NOT NULL))
            )');
            DB::statement('INSERT INTO working_memory_inputs__new
                (id, working_memory_version_id, thought_id, source_version_id, contribution_type, weight, created_at, updated_at)
                SELECT id, working_memory_version_id, thought_id, NULL, contribution_type, weight, created_at, updated_at
                FROM working_memory_inputs');
            DB::statement('DROP TABLE working_memory_inputs');
            DB::statement('ALTER TABLE working_memory_inputs__new RENAME TO working_memory_inputs');
            DB::statement('CREATE UNIQUE INDEX working_memory_inputs_version_thought_unique ON working_memory_inputs (working_memory_version_id, thought_id)');
            DB::statement('CREATE UNIQUE INDEX working_memory_inputs_version_source_unique ON working_memory_inputs (working_memory_version_id, source_version_id)');
            DB::statement('CREATE INDEX working_memory_inputs_version_type_idx ON working_memory_inputs (working_memory_version_id, contribution_type)');
            DB::statement('CREATE INDEX working_memory_inputs_thought_id_index ON working_memory_inputs (thought_id)');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('CREATE TABLE working_memory_inputs__down (
                id TEXT PRIMARY KEY NOT NULL,
                working_memory_version_id TEXT NOT NULL,
                thought_id TEXT NOT NULL,
                contribution_type VARCHAR(32) NOT NULL,
                weight NUMERIC(5,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (working_memory_version_id) REFERENCES working_memory_versions(id) ON DELETE CASCADE,
                FOREIGN KEY (thought_id) REFERENCES thoughts(id) ON DELETE CASCADE
            )');
            DB::statement('INSERT INTO working_memory_inputs__down
                (id, working_memory_version_id, thought_id, contribution_type, weight, created_at, updated_at)
                SELECT id, working_memory_version_id, thought_id, contribution_type, weight, created_at, updated_at
                FROM working_memory_inputs
                WHERE thought_id IS NOT NULL');
            DB::statement('DROP TABLE working_memory_inputs');
            DB::statement('ALTER TABLE working_memory_inputs__down RENAME TO working_memory_inputs');
            DB::statement('CREATE UNIQUE INDEX working_memory_inputs_version_thought_unique ON working_memory_inputs (working_memory_version_id, thought_id)');
            DB::statement('CREATE INDEX working_memory_inputs_version_type_idx ON working_memory_inputs (working_memory_version_id, contribution_type)');
            DB::statement('CREATE INDEX working_memory_inputs_thought_id_index ON working_memory_inputs (thought_id)');
            DB::statement('PRAGMA foreign_keys = ON');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE working_memory_inputs DROP CONSTRAINT IF EXISTS working_memory_inputs_input_target_chk');
            DB::statement('ALTER TABLE working_memory_inputs ALTER COLUMN thought_id SET NOT NULL');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE working_memory_inputs DROP CHECK working_memory_inputs_input_target_chk');
            DB::statement('ALTER TABLE working_memory_inputs MODIFY thought_id CHAR(36) NOT NULL');
        }

        Schema::table('working_memory_inputs', function (Blueprint $table): void {
            $table->dropUnique('working_memory_inputs_version_source_unique');
            $table->dropForeign('working_memory_inputs_source_version_fk');
            $table->dropColumn('source_version_id');
        });
    }
};
