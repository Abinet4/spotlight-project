<?php

namespace Tests\Unit;

use App\Support\RetryingDatabaseStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

// No RefreshDatabase here: its wrapping transaction would count as the caller's transaction.
class RetryingDatabaseStoreTest extends TestCase
{
    private function store(): RetryingDatabaseStore
    {
        return new class(DB::connection(), 'cache') extends RetryingDatabaseStore
        {
            public function attempt(callable $write): mixed
            {
                return $this->retrying($write);
            }
        };
    }

    private function deadlock(): QueryException
    {
        return new QueryException('mysql', 'insert ignore into `cache` ...', [],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
    }

    public function test_a_deadlocked_write_is_tried_again(): void
    {
        $calls = 0;
        $result = $this->store()->attempt(function () use (&$calls) {
            if (++$calls < 3) {
                throw $this->deadlock();
            }

            return 'saved';
        });

        $this->assertSame('saved', $result);
        $this->assertSame(3, $calls);
    }

    public function test_it_gives_up_after_four_tries(): void
    {
        $calls = 0;
        try {
            $this->store()->attempt(function () use (&$calls) {
                $calls++;
                throw $this->deadlock();
            });
            $this->fail('Expected the deadlock to be thrown.');
        } catch (QueryException) {
            $this->assertSame(4, $calls);
        }
    }

    public function test_other_database_errors_are_not_tried_again(): void
    {
        $calls = 0;
        try {
            $this->store()->attempt(function () use (&$calls) {
                $calls++;
                throw new QueryException('mysql', 'insert ...', [], new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'cache' doesn't exist"));
            });
            $this->fail('Expected the error to be thrown.');
        } catch (QueryException) {
            $this->assertSame(1, $calls);
        }
    }

    public function test_inside_a_transaction_the_deadlock_goes_to_the_caller(): void
    {
        $calls = 0;
        try {
            DB::transaction(function () use (&$calls) {
                $this->store()->attempt(function () use (&$calls) {
                    $calls++;
                    throw $this->deadlock();
                });
            });
            $this->fail('Expected the deadlock to be thrown.');
        } catch (QueryException) {
            $this->assertSame(1, $calls);
        }
    }

    public function test_a_lock_wait_that_timed_out_is_not_tried_again(): void
    {
        $calls = 0;
        try {
            $this->store()->attempt(function () use (&$calls) {
                $calls++;
                throw new QueryException('mysql', 'update `cache` ...', [], new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
            });
            $this->fail('Expected the error to be thrown.');
        } catch (QueryException) {
            $this->assertSame(1, $calls);
        }
    }

    public function test_a_write_inside_another_write_is_not_tried_on_its_own(): void
    {
        $store = $this->store();
        $inner = 0;
        $outer = 0;
        $result = $store->attempt(function () use ($store, &$inner, &$outer) {
            $outer++;

            return $store->attempt(function () use (&$inner) {
                if (++$inner < 2) {
                    throw $this->deadlock();
                }

                return 'saved';
            });
        });

        $this->assertSame('saved', $result);
        $this->assertSame([2, 2], [$outer, $inner]);
    }
}
