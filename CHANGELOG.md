# Release Notes

## v0.1.0 - 2026-11-06

First release of Datawell AI.

- Turns registered data sources and actions into permission-scoped tools, either one set per source or a compact set covering all of them.
- Chart tools that return chart payloads for a chat UI to render.
- A `laravel/ai` adapter: `DatawellTools::for($user, $mode)`.
- Requires `datawell/datawell` ^0.1, `laravel/ai` ^1.3, PHP 8.3+ and Laravel 13.
