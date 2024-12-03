<?php

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;

class SqlBuild
{
    // $build = new SqlBuild();
    // $build->table('users')->select(
    //  "name as u_name",
    //  "gender as sex", 'created_at'
    //)->where(
    //  $build->eq('id', 3),
    //  $build->or,
    //  $build->gt('created_at', now())
    //)->loop_assoc(false)

    private OrmQueryType $query_type;
    private array $columns;
    private string $clause;
    private bool $debug_query = false;
    private bool $catch_error = false;
    private bool $use_generator = false;

    public const or = " OR ";
    public const and = " AND ";

    /// START UTILITY
    ///
    public function eq(string $column, mixed $value, bool $unquote = false) : string
    {
        if($value == null)
            $unquote = true;

        if($unquote)
            return " `$column`=$value ";

        return " `$column`='$value' ";
    }

    public function gt(string $column, mixed $value, bool $unquote = false) : string
    {
        if($value == null)
            $unquote = true;

        if($unquote)
            return " `$column` > $value ";

        return " `$column` > '$value' ";
    }

    public function gte(string $column, mixed $value, bool $unquote = false) : string
    {
        if($value == null)
            $unquote = true;

        if($unquote)
            return " `$column` >= $value ";

        return " `$column` >= '$value' ";
    }

    public function lt(string $column, mixed $value, bool $unquote = false) : string
    {
        if($value == null)
            $unquote = true;

        if($unquote)
            return " `$column` < $value ";

        return " `$column` < '$value' ";
    }

    public function lte(string $column, mixed $value, bool $unquote = false) : string
    {
        if($value == null)
            $unquote = true;

        if($unquote)
            return " `$column` <= $value ";

        return " `$column` <= '$value' ";
    }

    public function wrap(string ...$operations) : string
    {
        return "( " . join(' ', $operations) . " )";
    }

    ///
    /// END UTILITY

    public function __construct(private string $sql_table) {}

    public function table(string $table) : self
    {
        $this->sql_table = $table;
        return $this;
    }

    public function debug() : self
    {
        $this->debug_query = true;
        return $this;
    }

    public function catch() : self
    {
        $this->catch_error = true;
        return $this;
    }

    /**
     * Relevant in a select loop. It instructs the builder to use a \Generator rather than return an array of results.
     * You can use a generator and yield the result as needed
     *
     * @return $this
     */
    public function generator() : self
    {
        $this->use_generator = true;
        return $this;
    }

    /**
     * @throws \Exception
     */
    public function select(string ...$columns) : self
    {
        $this->check_table_set();

        $this->query_type = OrmQueryType::SELECT;

        $this->columns = $columns;

        return $this;
    }

    /**
     * Inserts to the database. Returns the inserted row if it detects an id column;
     * Otherwise it returns a true on success and false on fail
     * @param array $columns key value paired columns
     * @return array|bool
     */
    public function insert(array $columns) : array|bool
    {
        $this->check_table_set();

        if($this->debug_query)
            return SQL::new()->open($this->sql_table)->debug()->insert($columns);

        return SQL::new()->open($this->sql_table)->insert($columns);
    }

    /**
     * @throws \Exception
     */
    public function update(array $columns) : bool
    {
        $this->check_table_set();

        return SQL::new()->open($this->sql_table)->column($columns)->clause($this->clause)->edit();
    }

    public function where(string ...$clause) : self
    {
        $this->clause = "WHERE " . join(" ", $clause) . " ";
        return $this;
    }

    private function select_query() : string
    {

    }

    /**
     * @throws \Exception
     */
    public function assoc(bool $allow_null = true) : ?array
    {
        if(!in_array($this->query_type, [OrmQueryType::SELECT, OrmQueryType::COUNT]))
            self::exception("You are fetch a query as an associative array when it's not a select query", "WrongQueryType");

        return $this->build_query([
            'fetch_as' => OrmReturnType::ASSOC,
            'can_be_null' => $allow_null,
            'loop' => false,
            'return_as' => OrmReturnType::RESULT,
        ]);
    }

    /**
     * @throws \Exception
     */
    public function loop_assoc(bool $allow_null = true) : ?array
    {
        if(!in_array($this->query_type, [OrmQueryType::SELECT, OrmQueryType::COUNT]))
            self::exception("You are fetch a query as an associative array when it's not a select query", "WrongQueryType");

        return $this->build_query([
            'fetch_as' => OrmReturnType::ASSOC,
            'can_be_null' => $allow_null,
            'loop' => true,
            'return_as' => $this->use_generator ? OrmReturnType::GENERATOR : OrmReturnType::RESULT,
        ]);
    }

    private function check_table_set() : void
    {
        if(!isset($this->sql_table))
            self::exception(
                "You must define the `table` before building your query. Start your query building by calling `->table` or inserting the table in the class instantiation",
                "TableNotSet"
            );
    }

    private function build_query(string $query, array $option): \mysqli_result|array|bool|int|\SQLite3Result|\Generator|null
    {
        $option = array_merge($option, [
            'debug' => $this->debug_query,
            'query_type' => $this->query_type,
            'catch' => $this->catch_error,
            'return_as' => $this->catch_error,
        ]);

        return SQL::new()->query($query, $option);
    }

    /**
     * @throws \Exception
     */
    private static function exception(string $message, ?string $title = null, ?\Throwable $exception = null) : void
    {
        $build = new self('user');

        $build->select()
            ->where(
                $build->wrap(
                    $build->eq('id', 2),
                    $build::and,
                    $build->eq('deleted', 0),
                ),
                $build::or,
                $build->eq('deleted', 1),
            )->assoc();

        $build->insert([
            'id' => 'uuid()',
            'name' => 'Oga Ebo',

        ]);

        LayException::throw_exception($message, "SqlBuild_Err" . ($title ? "::" . $title : ''), exception: $exception);
    }
}