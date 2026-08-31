<?php

declare(strict_types=1);

namespace Datawell\Ai\Tests\Fixtures\Sources;

use Datawell\Ai\Tests\Fixtures\Models\Tag;
use Datawell\Attributes\Model;
use Datawell\DataSource;
use Datawell\Fields\TextField;
use Datawell\Params;
use Datawell\Representation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

#[Model(Tag::class)]
class Tags extends DataSource
{
    public function key(): string
    {
        return 'tags';
    }

    public function description(): string
    {
        return 'Labels that can be attached to people.';
    }

    public function representation(): Representation
    {
        return Representation::make(label: 'name');
    }

    public function query(Params $params): EloquentBuilder|QueryBuilder
    {
        return Tag::query();
    }

    public function fields(): array
    {
        return [
            TextField::make('name')->sortable()->filterable()->searchable(),
        ];
    }
}
