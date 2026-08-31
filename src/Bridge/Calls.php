<?php

declare(strict_types=1);

namespace Datawell\Ai\Bridge;

use Closure;
use Datawell\Exceptions\SourceNotFoundException;
use Datawell\Execution\Channel;
use Datawell\Executor;
use Datawell\Validation\ValidationException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The bridge's one door into the executor: every call runs on the delegated,
 * interactive channel as the user, and every failure the core reports comes back
 * as a result payload — an agent can read `error`/`errors` and correct itself
 * (D38), where an exception would end its turn.
 */
final class Calls
{
    public function __construct(private readonly Executor $executor) {}

    /**
     * @param  Closure(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    public function guard(Closure $call): array
    {
        try {
            return $call();
        } catch (ValidationException $exception) {
            return ['error' => $exception->getMessage(), 'errors' => $exception->errors()];
        } catch (SourceNotFoundException) {
            return ['error' => 'Not found.'];
        }
    }

    /**
     * @param  array<string, mixed>  $wire
     * @return array<string, mixed>
     */
    public function query(Authenticatable $user, array $wire): array
    {
        return $this->guard(fn (): array => $this->executor->run($wire, $user, Channel::DelegatedInteractive)->toArray());
    }

    /**
     * @param  array<string, mixed>  $wire
     * @return array<string, mixed>
     */
    public function act(Authenticatable $user, array $wire, ?string $approval): array
    {
        if ($approval !== null) {
            $wire['approval'] = $approval;
        }

        return $this->guard(fn (): array => $this->executor->act($wire, $user, Channel::DelegatedInteractive)->toArray());
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function lookup(Authenticatable $user, string $source, int|string $id, array $parameters): array
    {
        return $this->guard(fn (): array => $this->executor
            ->lookup($source, $id, $user, $parameters, Channel::DelegatedInteractive)
            ?->toArray() ?? ['error' => 'Not found.']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    public function values(Authenticatable $user, string $source, int|string $id, string $field, array $parameters, array $page): array
    {
        return $this->guard(fn (): array => $this->executor
            ->values($source, $id, $field, $user, $parameters, $page, Channel::DelegatedInteractive)
            ?->toArray() ?? ['error' => 'Not found.']);
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(Authenticatable $user, string $runId): array
    {
        return $this->guard(fn (): array => $this->executor->progress($runId, $user)?->toArray() ?? ['error' => 'Not found.']);
    }

    /**
     * How many rows a query target covers — the count a person must see before
     * approving a query-scoped action (D40). Null when the query itself is invalid;
     * the action call will then report the validation errors properly.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $query
     */
    public function countScope(Authenticatable $user, string $source, array $parameters, array $query): ?int
    {
        $wire = ['source' => $source, 'parameters' => $parameters, 'page' => ['number' => 1, 'size' => 1, 'withTotal' => true]];

        foreach (['filters', 'search'] as $key) {
            if (isset($query[$key])) {
                $wire[$key] = $query[$key];
            }
        }

        $result = $this->query($user, $wire);
        $total = $result['meta']['total'] ?? null;

        return is_int($total) ? $total : null;
    }
}
