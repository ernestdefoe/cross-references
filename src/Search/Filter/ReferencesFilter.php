<?php

namespace Ernestdefoe\CrossReferences\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;
use Illuminate\Database\Eloquent\Builder;

/**
 * `references:42` → discussions whose posts reference discussion #42 (i.e.
 * any cross_references row where target_discussion_id = 42 maps source
 * discussions back). Negated variant `-references:42` excludes them.
 *
 * Value coerced through ValidateFilterTrait + `(int)` cast — defeats both
 * SQL-shape inputs and the §10 wildcard / sort-allowlist trap surface; the
 * gambit only accepts numeric ids, never strings.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ReferencesFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'references';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $value = $this->asString($value);
        $id = (int) $value;

        if ($id <= 0) {
            return;
        }

        $method = $negate ? 'whereNotIn' : 'whereIn';

        /** @var Builder<\Flarum\Discussion\Discussion> $query */
        $query = $state->getQuery();

        $query->{$method}('discussions.id', function ($sub) use ($id) {
            $sub->select('source_discussion_id')
                ->from('cross_references')
                ->where('target_discussion_id', $id);
        });
    }
}
