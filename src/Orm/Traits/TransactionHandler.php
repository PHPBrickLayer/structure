<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm\Traits;


use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Orm\Enums\OrmExecStatus;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ExpectedValues;

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

        return self::new()->get_link()->begin_transaction($flags, $name);
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

        if(self::$BEGIN_TRANSACTION_COUNTER == 0)
            return self::new()->get_link()->commit($flags, $name);

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
        return self::new()->get_link()->rollback($flags, $name);
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
     * @param callable $scoped_operations
     * @param int $flags [optional] A bitmask of MYSQLI_TRANS_COR_* constants.
     * @param string|null $name [optional] If provided then ROLLBACK $name is executed.
     * @return array
     */
    #[ArrayShape([
        'status' => 'bool',
        'message' => 'string',
        'exception' => '?Throwable',
        'data' => 'mixed',
    ])]
    final public static function scoped_transaction(
        callable $scoped_operations,
        bool $throw_exception = true,
        #[ExpectedValues([
            MYSQLI_TRANS_START_READ_ONLY,
            MYSQLI_TRANS_START_READ_WRITE,
            MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
        ])] int $flags = 0,
        ?string $name = null
    ) : array
    {
        try{
            self::new()->begin_transaction($flags, $name);
            $output = $scoped_operations(self::new()) ?? null;
            self::new()->commit_or_rollback($flags, $name);

            return [
                "status" => true,
                "message" => "Operation successful",
                "data" => $output
            ];
        } catch (\Throwable $exception) {
            self::new()->rollback();

            if($throw_exception)
                LayException::throw_exception("", "ScopedTransactionException", exception: $exception);

            LayException::log("", exception: $exception, log_title: "ScopedTransactionLog");

            return [
                "status" => false,
                "message" => "Operation failed",
                "exception" => $exception,
            ];
        }
    }
}