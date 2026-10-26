<?php

use App\Support\PersonNameSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('person_name_search_tokens')) {
            Schema::create('person_name_search_tokens', function (Blueprint $table): void {
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('field', 5);
            $table->char('token', 64);
            $table->primary(['person_id', 'field', 'token'], 'pnst_person_field_token_pk');
            $table->index(['organization_id', 'field', 'token'], 'pnst_org_field_token_idx');
            });
        }

        // MariaDB may be using the composite name index to enforce the
        // people.organization_id foreign key. Supply a separate supporting
        // index BEFORE dropping the composite index (MySQL error 1553).
        // Each operation is guarded because DDL can be partially committed.
        if (! Schema::hasIndex('people', 'people_org_fk_support_idx')) {
            Schema::table('people', function (Blueprint $table): void {
                $table->index('organization_id', 'people_org_fk_support_idx');
            });
        }

        if (Schema::hasIndex('people', 'people_organization_id_last_name_first_name_index')) {
            Schema::table('people', function (Blueprint $table): void {
                $table->dropIndex('people_organization_id_last_name_first_name_index');
            });
        }

        Schema::table('people', function (Blueprint $table): void {
            $table->text('first_name')->change();
            $table->text('last_name')->change();
        });

        DB::table('people')->orderBy('id')->chunkById(100, function ($people): void {
            foreach ($people as $person) {
                $values = [];
                $names = [];
                foreach (['first_name', 'last_name'] as $field) {
                    $stored = (string) $person->{$field};
                    try {
                        $plaintext = Crypt::decryptString($stored);
                    } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                        $plaintext = $stored;
                        $values[$field] = Crypt::encryptString($plaintext);
                    }
                    if ($plaintext === '') {
                        throw new RuntimeException('Empty person name for ID '.$person->id.'. Migration stopped.');
                    }
                    $names[$field] = $plaintext;
                }
                DB::transaction(function () use ($person, $values, $names): void {
                    if ($values !== []) {
                        DB::table('people')->where('id', $person->id)->update($values);
                    }
                    PersonNameSearch::sync((int) $person->id, (int) $person->organization_id, $names['first_name'], $names['last_name']);
                });
            }
        });
    }

    public function down(): void
    {
        // Intentionally do not restore plaintext names on rollback.
        // Coordinate rollback using a verified encrypted backup and the original APP_KEY.
    }
};
