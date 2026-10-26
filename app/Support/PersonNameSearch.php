<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Keyed, organization-bound blind tokens for partial name lookup. */
class PersonNameSearch
{
    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    private static function digest(int $organizationId, string $value): string
    {
        return hash_hmac('sha256', 'wayalong.person-name.v1:'.$organizationId.':'.$value, app('encrypter')->getKey());
    }

    /** @return list<string> */
    private static function grams(string $value): array
    {
        $value = self::normalize($value);
        $length = mb_strlen($value, 'UTF-8');
        if ($length === 0) {
            return [];
        }
        $size = min(3, $length);
        $grams = [];
        for ($index = 0; $index <= $length - $size; $index++) {
            $grams[] = mb_substr($value, $index, $size, 'UTF-8');
        }
        return array_values(array_unique($grams));
    }

    public static function sync(int $personId, int $organizationId, string $first, string $last): void
    {
        $rows = [];
        foreach (['first' => $first, 'last' => $last] as $field => $name) {
            foreach ([1, 2, 3] as $size) {
                $length = mb_strlen(self::normalize($name), 'UTF-8');
                if ($length < $size) {
                    continue;
                }
                for ($index = 0; $index <= $length - $size; $index++) {
                    $fragment = mb_substr(self::normalize($name), $index, $size, 'UTF-8');
                    $hash = self::digest($organizationId, $fragment);
                    $rows[$field.':'.$hash] = [
                        'person_id' => $personId,
                        'organization_id' => $organizationId,
                        'field' => $field,
                        'token' => $hash,
                    ];
                }
            }
        }
        DB::transaction(function () use ($personId, $rows): void {
            DB::table('person_name_search_tokens')->where('person_id', $personId)->delete();
            foreach (array_chunk(array_values($rows), 250) as $chunk) {
                DB::table('person_name_search_tokens')->insert($chunk);
            }
        });
    }

    public static function constrain(Builder $query, string $search, int $organizationId): Builder
    {
        $grams = self::grams($search);
        if ($grams === []) {
            return $query;
        }
        return $query->where(function (Builder $names) use ($grams, $organizationId): void {
            foreach (['first', 'last'] as $field) {
                $names->orWhere(function (Builder $candidate) use ($grams, $organizationId, $field): void {
                    foreach ($grams as $gram) {
                        $token = self::digest($organizationId, $gram);
                        $candidate->whereExists(function ($exists) use ($organizationId, $field, $token): void {
                            $exists->selectRaw('1')->from('person_name_search_tokens as name_tokens')
                                ->whereColumn('name_tokens.person_id', 'people.id')
                                ->where('name_tokens.organization_id', $organizationId)
                                ->where('name_tokens.field', $field)
                                ->where('name_tokens.token', $token);
                        });
                    }
                });
            }
        });
    }
}
