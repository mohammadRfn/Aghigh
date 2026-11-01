<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Catalog\Exceptions\CatalogProblem;
use Modules\Identity\Contracts\CurrentUser;
use Throwable;

final class CatalogWriter
{
    public function __construct(private readonly CatalogVersions $versions) {}

    /**
     * @template T of Model
     *
     * @param  list<string>  $fields
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(CurrentUser $actor, string $entity, array $fields, Closure $operation): Model
    {
        try {
            $lock = CatalogState::lock('catalog:admin-write');
            $lock->block((int) config('catalog.cache_lock_wait_seconds'));
        } catch (Throwable $error) {
            PageCache::warning($error);
            throw new CatalogProblem('Catalog writes are temporarily unavailable.', 503, 'catalog-unavailable');
        }
        $committed = false;
        try {
            try {
                $this->versions->initialize();
                if (CatalogState::store()->increment(CatalogVersions::DIRTY) === false) {
                    throw new \RuntimeException('Cannot mark write in progress.');
                }
            } catch (Throwable $error) {
                PageCache::warning($error);
                throw new CatalogProblem('Catalog writes are temporarily unavailable.', 503, 'catalog-unavailable');
            }
            try {
                $model = DB::transaction($operation, 3);
                $committed = true;
            } catch (QueryException $error) {
                $this->finish(false);
                if (in_array((string) $error->getCode(), ['23000', '23505', '23503'], true)) {
                    throw new CatalogProblem('A unique value or catalog reference conflicts with existing data.');
                }
                throw $error;
            } catch (Throwable $error) {
                $this->finish(false);
                throw $error;
            }
            if (! $model->wasRecentlyCreated) {
                $fields = array_values(array_intersect($fields, [...array_keys($model->getChanges()), 'images']));
            }
            sort($fields);
            Log::channel('audit')->info('catalog.write', [
                'actor_id' => $actor->id, 'entity' => $entity, 'entity_id' => (string) $model->getKey(), 'changed_fields' => $fields,
            ]);
            $this->finish(true);

            return $model;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $error) {
                Log::channel('app')->warning('Catalog write lock release failed.', ['committed' => $committed, 'exception' => $error::class]);
            }
        }
    }

    private function finish(bool $committed): void
    {
        try {
            if ($committed) {
                $this->versions->bump();
            }
            if (CatalogState::store()->decrement(CatalogVersions::DIRTY) === false) {
                throw new \RuntimeException('Cannot finish invalidation.');
            }
        } catch (Throwable $error) {
            // The positive dirty counter survives state recovery. Never serve an old
            // namespace after a committed write whose invalidation could not finish.
            PageCache::warning($error);
            if ($committed) {
                throw new CatalogProblem('Write committed; cache invalidation needs recovery. Inspect the entity before retrying.', 503, 'catalog-invalidation-pending');
            }
        }
    }
}
