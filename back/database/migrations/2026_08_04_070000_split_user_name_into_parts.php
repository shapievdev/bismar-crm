<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the single `name` column into the three parts a Russian record needs.
 *
 * The full name is no longer stored: the model derives it from the parts, so
 * there is one source of truth and no column that can drift out of step with
 * the fields the profile form edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Nullable to begin with, because existing rows have nothing to put
            // here until the backfill below has run.
            $table->string('last_name')->nullable()->after('id');
            $table->string('first_name')->nullable()->after('last_name');
            $table->string('middle_name')->nullable()->after('first_name');
        });

        $this->backfill();

        Schema::table('users', function (Blueprint $table): void {
            // A given name is the one part every record ends up with: the
            // backfill always has at least the old name to put there.
            $table->string('first_name')->nullable(false)->change();

            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('id');
        });

        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'name' => $this->join([$user->last_name, $user->first_name, $user->middle_name]),
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->nullable(false)->change();

            $table->dropColumn(['last_name', 'first_name', 'middle_name']);
        });
    }

    /**
     * Distributes each existing name across the three new columns.
     */
    private function backfill(): void
    {
        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update($this->split((string) $user->name));
            }
        });
    }

    /**
     * Reads a name that was typed into one field.
     *
     * The word counts are guesses, but they are the best ones available: the
     * interface asked for "Имя и фамилия" and showed the answer verbatim, so
     * two words are almost always in that order, while three or more is the
     * official ФИО form. Nothing is discarded — a fourth word joins the
     * patronymic rather than being dropped.
     *
     * @return array{last_name: string|null, first_name: string, middle_name: string|null}
     */
    private function split(string $name): array
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match (true) {
            count($words) === 0 => ['last_name' => null, 'first_name' => $name, 'middle_name' => null],
            count($words) === 1 => ['last_name' => null, 'first_name' => $words[0], 'middle_name' => null],
            count($words) === 2 => ['last_name' => $words[1], 'first_name' => $words[0], 'middle_name' => null],
            default => [
                'last_name' => $words[0],
                'first_name' => $words[1],
                'middle_name' => implode(' ', array_slice($words, 2)),
            ],
        };
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function join(array $parts): string
    {
        return implode(' ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
    }
};
