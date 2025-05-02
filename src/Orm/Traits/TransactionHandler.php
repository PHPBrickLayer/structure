<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm\Traits;


use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Orm\Enums\OrmExecStatus;
use Closure;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ExpectedValues;
use Throwable;

trait TransactionHandler
{
    private static bool $DB_IN_TRANSACTION = false;
    private static int $BEGIN_TRANSACTION_COUNTER = 0;

    private static function decrease_counter() : void
    {
        self::$BEGIN_TRANSACTION_COUNTER--;

        if(self::$BEGIN_TRANSACTION_COUNTER < 0)
            self::$BEGIN_TRANSACTION_COUNTER = 0;
    }

    final public function __rollback_on_error() : void
    {
        if((self::$DB_IN_TRANSACTION || self::$BEGIN_TRANSACTION_COUNTER == 0) && isset(self::$link))
            $this->rollback();
    }

    final public function in_transaction() : bool
    {
        return self::$DB_IN_TRANSACTION;
    }

    /**
     * Starts a transaction
     * @link https://secure.php.net/manual/en/mysqli.begin-transaction.php
     * @param int $flags
     * @param string|null $name
     * @return bool true on success or false on failure.
     */
    final public function begin_transaction(#[ExpectedValues([
        MYSQLI_TRANS_START_READ_ONLY,
        MYSQLI_TRANS_START_READ_WRITE,
        MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
    ])] int $flags = 0, ?string $name = null) : bool
    {
        self::$DB_IN_TRANSACTION = true;
        self::$BEGIN_TRANSACTION_COUNTER++;

        if(self::$DB_IN_TRANSACTION && !$name)
            return true;

        $link = self::new()->get_link();

        if(method_exists($link,"begin_transaction")) {
            try {
                return $link->begin_transaction($flags, $name);
            } catch (\Throwable $exception) {

            }
        }

        if(method_exists($link,"exec")) {
            try{
                return $link->exec("BEGIN;");
            } catch (\Throwable $exception){}
        }

        return false;
    }

    /**
     * Commits the current transaction
     * @link https://php.net/manual/en/mysqli.commit.php
     * @param int $flags A bitmask of MYSQLI_TRANS_COR_* constants.
     * @param string|null $name If provided then COMMIT $name is executed.
     * @return bool true on success or false on failure.
     */
    final public function commit(#[ExpectedValues([
        MYSQLI_TRANS_START_READ_ONLY,
        MYSQLI_TRANS_START_READ_WRITE,
        MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
    ])] int $flags = 0, ?string $name = null) : bool
    {
        self::decrease_counter();

        if(self::$BEGIN_TRANSACTION_COUNTER == 0) {
            $link = self::new()->get_link();

            if(method_exists($link,"commit")) {
                try {
                    return $link->commit($flags, $name);
                } catch (\Throwable $exception) {}
            }

            if(method_exists($link,"exec")) {
                try{
                    return $link->exec("COMMIT;");
                } catch (\Throwable $exception){}
            }
        }

        return false;
    }

    /**
     * Rolls back current transaction
     * @link https://php.net/manual/en/mysqli.rollback.php
     * @param int $flags [optional] A bitmask of MYSQLI_TRANS_COR_* constants.
     * @param string|null $name [optional] If provided then ROLLBACK $name is executed.
     * @return bool true on success or false on failure.
     */
    final public function rollback(#[ExpectedValues([
        MYSQLI_TRANS_START_READ_ONLY,
        MYSQLI_TRANS_START_READ_WRITE,
        MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
    ])] int $flags = 0, ?string $name = null) : bool
    {
        self::decrease_counter();
        $link = self::new()->get_link();

        if(!$link || isset($link->connect_error)) return false;

        if(method_exists($link,"rollback")) {
            try{
                return $link->rollback($flags, $name);
            } catch (\Throwable $exception){}
        }

        if(method_exists($link,"exec")) {
            try{
                return $link->exec("ROLLBACK;");
            } catch (\Throwable $exception){}
        }

        return false;
    }

    final public function commit_or_rollback(#[ExpectedValues([
        MYSQLI_TRANS_START_READ_ONLY,
        MYSQLI_TRANS_START_READ_WRITE,
        MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
    ])] int $flags = 0, ?string $name = null) : bool
    {
        if($this->query_info['status'] == OrmExecStatus::FAIL)
            return $this->rollback($flags, $name);

        if($this->query_info['status'] == OrmExecStatus::SUCCESS)
            return $this->commit($flags, $name);

        return false;
    }

    /**
     * This function wraps all your operations in a callback function, inside a transaction, and also wrapped in a try catch block
     *
     * @param callable(static): array{
     *     status: 'success' | 'warning' | 'error',
     *     message: 'COMMIT' | 'ROLLBACK' | 'EXCEPTION',
     *     data: mixed,
     * } $scoped_operations The operation that should be run in the transaction block.
     * It must return an array with the key `status` included. and to commit your transaction,
     * `status` must equal `success`
     * @param int $flags [optional] A bitmask of MYSQLI_TRANS_COR_* constants.
     * @param string|null $name [optional] If provided then ROLLBACK $name is executed.
     *
     * @return array{
     *     status: bool,
     *     message: 'COMMIT'|'EXCEPTION'|'ROLLBACK',
     *     exception?: \Throwable,
     *     data?: null|array{
     *        status: 'error'|'success'|'warning',
     *        message: 'COMMIT'|'EXCEPTION'|'ROLLBACK',
     *        data: mixed
     *     }
 *      }
     *
     * @throws \Exception
     */
    final public static function scoped_transaction(
        callable $scoped_operations,
        bool $throw_exception = true,
        ?callable $on_exception = null,
        #[ExpectedValues([
            MYSQLI_TRANS_START_READ_ONLY,
            MYSQLI_TRANS_START_READ_WRITE,
            MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
        ])] int $flags = 0,
        ?string $name = null,
    ) : array
    {
        try{
            self::new()->begin_transaction($flags, $name);

            $output = $scoped_operations(self::new()) ?? null;
            $commit = $output['status'] == "success";

            if($commit)
                self::new()->commit($flags, $name);
            else
                self::new()->rollback($flags, $name);

            return [
                "status" => true,
                "message" => $commit ? "COMMIT" : "ROLLBACK",
                "data" => $output
            ];
        } catch (\Throwable $exception) {
            self::new()->rollback();

            if($on_exception !== null) {
                $on_exception($exception);

                return [
                    "status" => false,
                    "message" => "EXCEPTION",
                    "exception" => $exception,
                ];
            }

            if($throw_exception)
                LayException::throw_exception("", "ScopedTransactionException", exception: $exception);

            LayException::log("", exception: $exception, log_title: "ScopedTransactionLog");

            return [
                "status" => false,
                "message" => "EXCEPTION",
                "exception" => $exception,
            ];
        }
    }
}