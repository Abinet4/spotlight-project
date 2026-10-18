<?php

namespace App\Support;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The database cache, but a write that MySQL ends as a deadlock is tried again instead of failing the
 * request. A browser sends a page's requests together, so the request limiter adds and counts the same
 * cache rows from several requests at once; when a minute's counter starts over, MySQL picks one of them
 * as the deadlock loser and the visitor got a server error. Each of these writes is safe to repeat.
 */
class RetryingDatabaseStore extends DatabaseStore
{
    use DetectsConcurrencyErrors;

    private const TRIES = 4;

    private bool $retrying = false;

    public function get($key)
    {
        return $this->retrying(fn () => parent::get($key));
    }

    public function many(array $keys)
    {
        return $this->retrying(fn () => parent::many($keys));
    }

    public function put($key, $value, $seconds)
    {
        return $this->retrying(fn () => parent::put($key, $value, $seconds));
    }

    public function putMany(array $values, $seconds)
    {
        return $this->retrying(fn () => parent::putMany($values, $seconds));
    }

    public function add($key, $value, $seconds)
    {
        return $this->retrying(fn () => parent::add($key, $value, $seconds));
    }

    public function increment($key, $value = 1)
    {
        return $this->retrying(fn () => parent::increment($key, $value));
    }

    public function decrement($key, $value = 1)
    {
        return $this->retrying(fn () => parent::decrement($key, $value));
    }

    public function touch($key, $seconds)
    {
        return $this->retrying(fn () => parent::touch($key, $seconds));
    }

    public function forget($key)
    {
        return $this->retrying(fn () => parent::forget($key));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    protected function retrying(callable $write): mixed
    {
        // add() reads the key first; the outer call is the one that tries again.
        if ($this->retrying) {
            return $write();
        }

        $this->retrying = true;
        try {
            for ($try = 1; ; $try++) {
                try {
                    return $write();
                } catch (QueryException $e) {
                    if ($try >= self::TRIES || ! $this->worthAnotherTry($e)) {
                        throw $e;
                    }
                    usleep(random_int(2_000, 10_000) * $try);
                }
            }
        } finally {
            $this->retrying = false;
        }
    }

    private function worthAnotherTry(QueryException $e): bool
    {
        // Inside a caller's transaction the deadlock already undid the caller's work, so only the caller
        // can start over. A lock wait that timed out has already waited (50 seconds by default); a
        // deadlock is ended by MySQL at once, so trying again costs little.
        return $this->getConnection()->transactionLevel() === 0
            && $this->causedByConcurrencyError($e)
            && ! Str::contains($e->getMessage(), ['Lock wait timeout', 'database is locked', 'database file is locked']);
    }
}
