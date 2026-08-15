<?php

namespace App\Support\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * Remembers which v2 record each v1 row became.
 *
 * The importer asks this before creating anything, which is what makes it safe
 * to run repeatedly: a second run finds the existing record and updates it
 * instead of making a duplicate.
 */
class LegacyMap
{
    private const TABLE = 'legacy_references';

    /**
     * In-memory cache for the run. An import touches thousands of rows and
     * would otherwise ask the same questions over and over.
     *
     * @var array<string, string|null>
     */
    private static array $cache = [];

    /**
     * The v2 uuid for a v1 id, or null if it has not been imported.
     */
    public static function find(string $entity, string|int|null $legacyId): ?string
    {
        if ($legacyId === null || $legacyId === '') {
            return null;
        }

        $key = $entity.':'.$legacyId;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $uuid = DB::table(self::TABLE)
            ->where('entity', $entity)
            ->where('legacy_id', (string) $legacyId)
            ->value('uuid');

        return self::$cache[$key] = $uuid;
    }

    /**
     * Record the mapping.
     *
     * @param  array<string, mixed>|null  $notes
     */
    public static function remember(string $entity, string|int $legacyId, string $uuid, ?array $notes = null): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['entity' => $entity, 'legacy_id' => (string) $legacyId],
            [
                'uuid' => $uuid,
                'notes' => $notes ? json_encode($notes) : null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        self::$cache[$entity.':'.$legacyId] = $uuid;
    }

    /**
     * How many of each entity have been imported.
     *
     * @return array<string, int>
     */
    public static function summary(): array
    {
        return DB::table(self::TABLE)
            ->select('entity', DB::raw('COUNT(*) as total'))
            ->groupBy('entity')
            ->pluck('total', 'entity')
            ->all();
    }

    /**
     * Record a mapping for this run only, without writing to the database.
     *
     * Used by a dry run so later steps can still resolve what earlier steps
     * would have created. Without it a dry run reports zero customers simply
     * because no sectors were really written, which hides the answer you ran
     * it for.
     */
    public static function rememberInMemory(string $entity, string|int $legacyId, string $uuid): void
    {
        self::$cache[$entity.':'.$legacyId] = $uuid;
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
