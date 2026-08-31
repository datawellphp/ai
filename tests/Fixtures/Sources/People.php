<?php

declare(strict_types=1);

namespace Datawell\Ai\Tests\Fixtures\Sources;

use Datawell\Actions\ActionContext;
use Datawell\Actions\LinkAction;
use Datawell\Actions\ServerAction;
use Datawell\Ai\Tests\Fixtures\Models\User;
use Datawell\Attributes\Model;
use Datawell\DataSource;
use Datawell\Enums\ActionTarget;
use Datawell\Enums\Confirmation;
use Datawell\Fields\BooleanField;
use Datawell\Fields\NumberField;
use Datawell\Fields\RelationField;
use Datawell\Fields\TextField;
use Datawell\Filters\Filter;
use Datawell\Operators\Operator;
use Datawell\Parameter;
use Datawell\Params;
use Datawell\Representation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

#[Model(User::class)]
class People extends DataSource
{
    public function key(): string
    {
        return 'people';
    }

    public function description(): string
    {
        return 'People in the workspace: name, contact email and activity.';
    }

    public function representation(): Representation
    {
        return Representation::make(label: 'name')->url(fn (User $user): string => "/people/{$user->id}");
    }

    public function query(Params $params): EloquentBuilder|QueryBuilder
    {
        return User::query()->where('workspace_id', 1);
    }

    public function fields(): array
    {
        return [
            TextField::make('name')->sortable()->filterable()->searchable(),
            TextField::make('email')->filterable()->searchable()->nullable()->visibleWhen('view-contact-details'),
            BooleanField::make('active')->filterable()->groupable(),
            RelationField::make('tags', from: 'tags')->references('tags')->filterable(),
            NumberField::make('tags_count')->countOf('tags')->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('active')->default(Operator::Is, true),
        ];
    }

    public function defaultSort(): array
    {
        return ['name' => 'asc'];
    }

    public function actions(): array
    {
        return [
            // Never needs confirmation: runs straight through even on a delegated channel.
            ServerAction::make('touch')->targets(ActionTarget::Single, ActionTarget::Many)
                ->description('Record that this person was checked.')
                ->confirmation(Confirmation::Never)
                ->handle(static function ($rows, $input, ActionContext $context): void {
                    $context->message('Checked.');
                }),
            // The default WhenDelegated policy: a human clicks through; an agent needs approval.
            ServerAction::make('flag')->targets(ActionTarget::Single, ActionTarget::Many)
                ->description('Mark this person for review.')
                ->parameters([Parameter::make('note')->rules(['nullable', 'string', 'max:100'])])
                ->handle(static function ($rows, $input, ActionContext $context): void {
                    foreach ($rows as $person) {
                        $person->update(['email' => 'flagged@acme.test']);
                    }
                }),
            // Query-scoped: approval with the resolved count.
            ServerAction::make('deactivate')->targets(ActionTarget::Many, ActionTarget::QueryScope)
                ->description('Deactivate people.')
                ->authorize(fn (Authenticatable $actor, User $row): bool => $row->active)
                ->authorizeQuery(static fn (EloquentBuilder|QueryBuilder $query) => $query->where('active', true))
                ->handle(static function ($rows, $input, ActionContext $context): void {
                    foreach ($rows as $person) {
                        $person->update(['active' => false]);
                    }
                }),
            // Never a tool.
            ServerAction::make('purge')->destructive()->humanOnly()->description('Erase this person.')->handle(static fn (): null => null),
            LinkAction::make('edit')->url(fn (User $user): string => "/people/{$user->id}/edit"),
        ];
    }
}
