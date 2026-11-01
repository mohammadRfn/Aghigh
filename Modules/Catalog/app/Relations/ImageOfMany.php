<?php

declare(strict_types=1);

namespace Modules\Catalog\Relations;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductImage;

/**
 * Laravel ofMany adds an id tie-break aggregate. PostgreSQL has no min(uuid).
 * Aggregate the UUID's canonical text, then cast it back; do not change IDs or
 * install a global aggregate. SQLite can aggregate its native UUID strings.
 *
 * @extends HasOne<ProductImage, Product>
 */
final class ImageOfMany extends HasOne
{
    protected function newOneOfManySubQuery($groupBy, $columns = null, $aggregate = null)
    {
        $query = (clone $this->query->getModel())->newQuery()->withoutGlobalScopes($this->removedScopes());
        foreach (Arr::wrap($groupBy) as $group) {
            $query->groupBy($this->qualifyRelatedColumn($group));
        }
        foreach ($columns ?? [] as $index => $column) {
            $grammar = $query->getQuery()->getGrammar();
            $wrapped = $grammar->wrap($query->qualifyColumn($column));
            $function = $index === 0 ? $aggregate : 'min';
            $expression = $column === 'id' && $query->getConnection()->getDriverName() === 'pgsql'
                ? "{$function}({$wrapped}::text)::uuid"
                : "{$function}({$wrapped})";
            $query->selectRaw($expression.' as '.$grammar->wrap($column.'_aggregate'));
        }
        $this->addOneOfManySubQueryConstraints($query, column: null, aggregate: $aggregate);

        return $query;
    }
}
