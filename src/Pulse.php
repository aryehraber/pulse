<?php

namespace Laravel\Pulse;

use Illuminate\Auth\AuthManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Lottery;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Entries\Entry;
use Laravel\Pulse\Entries\Update;
use RuntimeException;
use Throwable;

class Pulse
{
    use ListensForStorageOpportunities;

    /**
     * The list of metric recorders.
     *
     * @var \Illuminate\Support\Collection<int, object>
     */
    protected Collection $recorders;

    /**
     * The list of queued entries or updates.
     *
     * @var \Illuminate\Support\Collection<int, \Laravel\Pulse\Entries\Entry|\Laravel\Pulse\Entries\Update>
     */
    protected Collection $entries;

    /**
     * Indicates if Pulse should record entries.
     */
    protected bool $shouldRecord = true;

    /**
     * The entry filters.
     *
     * @var \Illuminate\Support\Collection<int, (callable(\Laravel\Pulse\Entries\Entry|\Laravel\Pulse\Entries\Update): bool)>
     */
    protected Collection $filters;

    /**
     * The users resolver.
     *
     * @var ?(callable(\Illuminate\Support\Collection<int, string|int>): iterable<int, array{id: int|string, name: string, 'email'?: ?string}>)
     */
    protected $usersResolver = null;

    /**
     * The callback that should be used to authorize Pulse users.
     *
     * @var ?(callable(\Illuminate\Http\Request): bool)
     */
    protected $authorizeUsing = null;

    /**
     * Indicates if Pulse migrations will be run.
     */
    protected bool $runsMigrations = true;

    /**
     * Handle exceptions using the given callback.
     *
     * @var ?(callable(\Throwable): mixed)
     */
    protected $handleExceptionsUsing = null;

    /**
     * The remembered user's ID.
     */
    protected int|string|null $rememberedUserId = null;

    /**
     * The authenticated user ID resolver.
     *
     * @var (callable(): int|string|null)
     */
    protected $authenticatedUserIdResolver = null;

    /**
     * Create a new Pulse instance.
     */
    public function __construct(
        protected Repository $config,
        protected AuthManager $auth,
        protected Application $app,
    ) {
        $this->filters = collect([]);
        $this->recorders = collect([]);

        $this->flushEntries();
    }

    /**
     * Register a recorder.
     *
     * @param  class-string|list<class-string>  $recorders
     */
    public function register(string|array $recorders): self
    {
        $recorders = collect($recorders)->map(fn ($recorder) => $this->app->make($recorder));

        $callback = fn (Dispatcher $event) => $recorders
            ->filter(fn ($recorder) => $recorder->listen ?? null)
            ->each(fn ($recorder) => $event->listen(
                $recorder->listen,
                fn ($event) => $this->rescue(fn () => Collection::wrap($recorder->record($event))
                    ->filter()
                    ->each($this->record(...)))
            ));

        $this->app->afterResolving('events', $callback);

        if ($this->app->resolved('events')) {
            $callback($this->app->make('events'));
        }

        $recorders
            ->filter(fn ($recorder) => method_exists($recorder, 'register'))
            ->each(function ($recorder) {
                $record = function (...$args) use ($recorder) {
                    $this->rescue(fn () => Collection::wrap($recorder->record(...$args))
                        ->filter()
                        ->each($this->record(...)));
                };

                $this->app->call($recorder->register(...), ['record' => $record]);
            });

        $this->recorders = collect([...$this->recorders, ...$recorders]);

        return $this;
    }

    /**
     * Stop recording entries.
     *
     * @template TReturn
     *
     * @param  (callable(): TReturn)  $callback
     * @return TReturn
     */
    public function ignore($callback): mixed
    {
        $cachedRecording = $this->shouldRecord;

        try {
            $this->shouldRecord = false;

            return $callback();
        } finally {
            $this->shouldRecord = $cachedRecording;
        }
    }

    /**
     * Stop recording entries.
     */
    public function stopRecording(): self
    {
        $this->shouldRecord = false;

        return $this;
    }

    /**
     * Start recording entries.
     */
    public function startRecording(): self
    {
        $this->shouldRecord = true;

        return $this;
    }

    /**
     * Filter incoming entries using the provided filter.
     *
     * @param  (callable(\Laravel\Pulse\Entries\Entry|\Laravel\Pulse\Entries\Update): bool)  $filter
     */
    public function filter(callable $filter): self
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Record the given entry.
     */
    public function record(Entry|Update $entry): self
    {
        if ($this->shouldRecord) {
            $this->entries[] = $entry;
        }

        return $this;
    }

    /**
     * Store the queued entries.
     */
    public function store(Ingest $ingest): self
    {
        if (! $this->shouldRecord) {
            $this->rememberedUserId = null;

            return $this->flushEntries();
        }

        $this->rescue(fn () => $ingest->ingest(
            $this->entries->map->resolve()->filter($this->shouldRecord(...)),
        ));

        $this->rescue(fn () => Lottery::odds(...$this->config->get('pulse.ingest.lottery'))
            ->winner(fn () => $ingest->trim())
            ->choose());

        $this->rememberedUserId = null;

        return $this->flushEntries();
    }

    /**
     * The pending entries to be recorded.
     *
     * @return \Illuminate\Support\Collection<int, \Laravel\Pulse\Entries\Entry|\Laravel\Pulse\Entries\Update>
     */
    public function entries()
    {
        return $this->entries;
    }

    /**
     * Flush the queue.
     */
    public function flushEntries(): self
    {
        $this->entries = collect([]);

        return $this;
    }

    /**
     * Get the tables used by the recorders.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function tables(): Collection
    {
        return $this->recorders
            ->map(fn ($recorder) => $recorder->table ?? null)
            ->flatten()
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Determine if the entry should be recorded.
     */
    protected function shouldRecord(Entry|Update $entry): bool
    {
        return $this->filters->every(fn (callable $filter) => $filter($entry));
    }

    /**
     * Resolve the user's details using the given closure.
     *
     * @param  (callable(\Illuminate\Support\Collection<int, string|int>): iterable<int, array{id: int|string, name: string, 'email'?: ?string}>)  $callback
     */
    public function resolveUsersUsing(callable $callback): self
    {
        $this->usersResolver = $callback;

        return $this;
    }

    /**
     * Resolve the user's details using the given closure.
     *
     * @param  \Illuminate\Support\Collection<int, string|int>  $ids
     * @return  \Illuminate\Support\Collection<int, array{id: string|int, name: string, 'email'?: ?string}>
     */
    public function resolveUsers(Collection $ids): Collection
    {
        if ($this->usersResolver) {
            return collect(($this->usersResolver)($ids));
        }

        if (class_exists(\App\Models\User::class)) {
            return \App\Models\User::whereKey($ids)->get(['id', 'name', 'email']);
        }

        if (class_exists(\App\User::class)) {
            return \App\User::whereKey($ids)->get(['id', 'name', 'email']);
        }

        return $ids->map(fn (string|int $id) => [
            'id' => $id,
            'name' => "User ID: {$id}",
        ]);
    }

    /**
     * Return the compiled CSS from the vendor directory.
     */
    public function css(): string
    {
        if (($content = file_get_contents(__DIR__.'/../dist/pulse.css')) === false) {
            throw new RuntimeException('Unable to load Pulse dashboard CSS.');
        }

        return $content;
    }

    /**
     * Return the compiled JavaScript from the vendor directory.
     */
    public function js(): string
    {
        if (($content = file_get_contents(__DIR__.'/../dist/pulse.js')) === false) {
            throw new RuntimeException('Unable to load the Pulse dashboard JavaScript.');
        }

        return $content;
    }

    /**
     * Determine if the given request can access the Pulse dashboard.
     */
    public function authorize(Request $request): bool
    {
        return ($this->authorizeUsing ?: fn () => $this->app->environment('local'))($request);
    }

    /**
     * Set the callback that should be used to authorize Pulse users.
     *
     * @param  (callable(\Illuminate\Http\Request): bool)  $callback
     */
    public function authorizeUsing(callable $callback): self
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * Configure Pulse to not register its migrations.
     */
    public function ignoreMigrations(): self
    {
        $this->runsMigrations = false;

        return $this;
    }

    /**
     * Determine if Pulse may run migrations.
     */
    public function runsMigrations(): bool
    {
        return $this->runsMigrations;
    }

    /**
     * Handle exceptions using the given callback.
     *
     * @param  (callable(\Throwable): mixed)  $callback
     */
    public function handleExceptionsUsing(callable $callback): self
    {
        $this->handleExceptionsUsing = $callback;

        return $this;
    }

    /**
     * Resolve the authenticated user ID with the given callback.
     */
    public function resolveAuthenticatedUserIdUsing(callable $callback): self
    {
        $this->authenticatedUserIdResolver = $callback;

        return $this;
    }

    /**
     * The authenticated user ID resolver.
     *
     * @return (callable(): (int|string|null|(callable(): (int|string|null))))
     */
    public function authenticatedUserIdResolver(): callable
    {
        if ($this->authenticatedUserIdResolver !== null) {
            return $this->authenticatedUserIdResolver;
        }

        if ($this->auth->hasUser()) {
            $id = $this->auth->id();

            return fn () => $id;
        }

        return fn () => $this->auth->id() ?? $this->rememberedUserId;
    }

    /**
     * Set the user for the given callback.
     *
     * @template TReturn
     *
     * @param  (callable(): TReturn)  $callback
     * @return TReturn
     */
    public function withUser(Authenticatable|int|string|null $user, callable $callback): mixed
    {
        $cachedUserIdResolver = $this->authenticatedUserIdResolver;

        try {
            $id = $user instanceof Authenticatable
                ? $user->getAuthIdentifier()
                : $user;

            $this->authenticatedUserIdResolver = fn () => $id;

            return $callback();
        } finally {
            $this->authenticatedUserIdResolver = $cachedUserIdResolver;
        }
    }

    /**
     * Remember the authenticated user's ID.
     */
    public function rememberUser(Authenticatable $user): self
    {
        $this->rememberedUserId = $user->getAuthIdentifier();

        return $this;
    }

    /**
     * Execute the given callback handling any exceptions.
     *
     * @param  (callable(): mixed)  $callback
     */
    public function rescue(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            ($this->handleExceptionsUsing ?? fn () => null)($e);
        }
    }
}
