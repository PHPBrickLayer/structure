<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm\Traits;


use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Orm\Enums\OrmExecStatus;
use BrickLayer\Lay\Orm\Enums\OrmTransactionMode;
use Closure;
use Exception;
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
        if(isset(self::$link) && ($this->get_link()->in_transaction() || self::$DB_IN_TRANSACTION))
            $this->rollback();
    }

    final public function in_transaction() : bool
    {
        return self::$DB_IN_TRANSACTION;
    }

    final public function begin_transaction(?OrmTransactionMode $flags = null, ?string $name = null) : bool
    {
        self::$BEGIN_TRANSACTION_COUNTER++;

        if(self::$DB_IN_TRANSACTION)
            return true;

        $t = $this->get_link()->begin_transaction($flags, $name, self::in_transaction());

        self::$DB_IN_TRANSACTION = true;

        return $t;
    }

    final public function commit(?OrmTransactionMode $flags = null, ?string $name = null) : bool
    {
        self::decrease_counter();

        if(self::$BEGIN_TRANSACTION_COUNTER == 0)
            return $this->get_link()->commit($flags, $name);

        return false;
    }

    final public function rollback(?OrmTransactionMode $flags = null, ?string $name = null) : bool
    {
        self::decrease_counter();

        $link = $this->get_link();

        if(!$link || !$link->is_connected()) return false;

        return $link->rollback($flags, $name);
    }

    final public function commit_or_rollback(?OrmTransactionMode $flags = null, ?string $name = null) : bool
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
     *     data: mixed,
     * } $scoped_operations The operation that should be executed in the transaction block.
     * It must return an array with the key `status` included. and to commit your transaction,
     * `status` must equal `success`
     * @param OrmTransactionMode|null $flags
     * @param string|null $name [optional] If provided then ROLLBACK $name is executed.
     *
     * @return array{
     *     status: bool,
     *     message: 'COMMIT'|'EXCEPTION'|'ROLLBACK',
     *     exception?: Throwable,
     *     data?: null|array{
     *        status: 'error'|'success'|'warning',
     *        message: 'COMMIT'|'EXCEPTION'|'ROLLBACK',
     *        data: mixed
     *     }
     * }
     *
     * @throws Exception
     */
    final public static function scoped_transaction(
        callable $scoped_operations,
        bool $throw_exception = true,
        ?callable $on_exception = null,
        ?OrmTransactionMode $flags = null,
    ) : array
    {
        $db = self::new();
        $db->begin_transaction($flags);

        try{
            $output = $scoped_operations($db) ?? null;
            $commit = @$output['status'] == "success";

            $data = $output['data'] ?? null;

            if(isset($output['message']))
                $data = $output;

            if($commit)
                $db->commit($flags);
            else
                $db->rollback($flags);

            return [
                "status" => true,
                "message" => $commit ? "COMMIT" : "ROLLBACK",
                "data" => $data
            ];
        } catch (Throwable $exception) {
            $db->rollback($flags);

            if($on_exception !== null) {
                $on_exception($exception);

                return [
                    "status" => false,
                    "message" => "EXCEPTION",
                    "exception" => $exception,
                ];
            }

            if($throw_exception)
                LayException::throw("", "ScopedTransactionException", $exception);

            LayException::log("", exception: $exception, log_title: "ScopedTransactionLog");

            return [
                "status" => false,
                "message" => "EXCEPTION",
                "exception" => $exception,
            ];
        }
    }
}