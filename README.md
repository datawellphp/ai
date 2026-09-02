<div align="center">
    <h1>Datawell AI</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/datawell/ai"><img src="https://img.shields.io/packagist/v/datawell/ai.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/datawell/ai"><img src="https://img.shields.io/packagist/php-v/datawell/ai.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/datawell/ai"><img src="https://badge.laravel.cloud/badge/datawell/ai?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/datawellphp/ai/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/datawell/ai/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/datawell/ai"><img src="https://img.shields.io/packagist/dt/datawell/ai.svg?style=flat-square" alt="Total Downloads"></a>
</p>

AI bridge for Datawell data sources: permission-scoped tools generated from the registry.

## Installation

You can install the package via Composer:

```bash
composer require datawell/ai
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="datawell-ai"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="datawell-ai-config"
```

## Usage

The bridge turns the sources a user may see into agent tools — generated from the
published schema, never written per source (D59/D60). With `laravel/ai`:

```php
use Datawell\Ai\Adapters\LaravelAi\DatawellTools;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class Assistant implements Agent, HasTools
{
    use Promptable;

    public function __construct(public User $user) {}

    public function instructions(): string
    {
        return 'You answer questions about the workspace using the tools.';
    }

    public function tools(): iterable
    {
        return DatawellTools::for($this->user);
    }
}
```

What the user gets, as that user: a `query_<source>` tool per visible source (filters,
operators, sorts, grains and enum values as real JSON-schema constraints; the source's
prose as the description), one tool per visible server action, and shared `lookup`,
`values` and `progress` tools. Hidden fields, gated actions and `humanOnly` actions are
simply absent. Every call runs on the delegated channel; validation failures come back
as tool results so the model corrects itself.

**Consent** rides on `laravel/ai`'s tool approvals: an action whose confirmation policy
is not `never` requests approval per call with a reason a person can act on
("Decline stale on Document signatures: 37 matching rows."); once approved, the tool-call
id is recorded as the approval reference in the run's audit row.

Set `datawell-ai.mode` to `compact` for a fixed tool list (`list_sources`,
`describe_source`, `query`, `act`) on large registries.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Datawell AI! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Ethan Barlow](https://github.com/EthanBarlo)
- [All Contributors](../../contributors)

## License

Datawell AI is open-sourced software licensed under the [MIT license](LICENSE.md).

## Chart tools

Alongside the query and action tools, the bridge publishes a chart tool per
groupable source (`chart_people`) — or one shared `chart` tool in compact mode.
It runs the grouped query server-side and returns a typed payload:

```json
{ "kind": "chart", "title": "…", "chart": { "type": "line", "options": {} },
  "source": "people", "request": { "groupBy": [...], "aggregates": [...] },
  "result": { "buckets": [...], "meta": {...}, "applied": {...} } }
```

Your chat front-end renders any tool result whose `kind` it recognises (the
`datawell/reports` `<ReportChart>` draws this one directly) while the model,
holding the buckets, narrates the takeaway. Set `datawell-ai.charts` to `false`
if your interface cannot draw charts.
