<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm\Traits;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\SQL;
use Generator;
use JetBrains\PhpStorm\ExpectedValues;

trait SelectorOOP
{
    private static int $current_index = 0;
    private array $cached_options = [];
    private bool $using_wrap = false;
    private int $wrap_index = 0;

    final public function open(string $table): self
    {
        self::$current_index++;

        if ($table)
            $this->table($table);

        return $this;
    }

    final public function table(string $table): self
    {
        return $this->store_vars('table', $table);
    }

    final public function value(string|array $values): self
    {
        return $this->store_vars('values', $values);
    }

    final public function switch(string $switch_id, string $column_for_condition, string $column_for_assignment): self
    {
        return $this->store_vars('switch', ["switch" => $column_for_condition, "column" => $column_for_assignment], $switch_id);
    }

    final public function case(string $switch_id, string $when_column_for_condition_is, string $then_column_for_assignment_is): self
    {
        return $this->store_vars('case', $then_column_for_assignment_is, $switch_id, $when_column_for_condition_is);
    }

    /**
     * @param 'right'|'inner'|'left'|'RIGHT'|'INNER'|'LEFT' $join_table
     * @param string $type
     */
    final public function join(string $join_table, #[ExpectedValues(['right', 'inner', 'left'])] string $type = ""): SQL
    {
        return $this->store_vars('join', ["table" => $join_table, "type" => $type,], true);
    }

    final public function on(string $col_from_child_table, string $col_from_parent_table): self
    {
        return $this->store_vars('on', ["child_table" => $col_from_child_table, "parent_table" => $col_from_parent_table], true);
    }

    final public function except(string $comma_separated_columns): self
    {
        return $this->store_vars('except', $comma_separated_columns);
    }

    private function process_condition_stmt(string $column, ?string $operator_or_value = null, ?string $value = null) : string
    {
        if($operator_or_value === null)
            return $column;

        if(!str_starts_with($column, "("))
            $column = self::escape_identifier($column);

        if(is_null($value)) {
            return str_starts_with($operator_or_value, "(") || strtolower($operator_or_value) == 'null' ?
                "$column=$operator_or_value" :
                "$column='$operator_or_value'";
        }

        return str_starts_with($value, "(") || strtolower($value) == 'null' ?
            "$column $operator_or_value $value" :
            "$column $operator_or_value '$value'";
    }

    final public function where_agr(string $column, string|array $operator_or_value, ?array $agr_values = null): self
    {
        if(is_array($operator_or_value)) {
            $agr_values = $operator_or_value;
            $operator_or_value = "=";
        }

        return $this->clause_agr("WHERE", $column, $operator_or_value, $agr_values);
    }

    final public function where(string $column, ?string $operator_or_value = null, ?string $value = null): self
    {
        $WHERE = $this->process_condition_stmt($column,$operator_or_value,$value);

        $prepend_where = @$this->cached_options[self::$current_index]['has_used_where'] ? "" : "WHERE";

        $this->set_where();

        return $this->clause_array("$prepend_where $WHERE");
    }

    final public function or_where(string $column, string $operator_or_value, ?string $value = null): self
    {
        $WHERE = $this->process_condition_stmt($column,$operator_or_value,$value);

        return $this->clause_array(" OR $WHERE");
    }

    final public function and_where(string $column, string $operator_or_value, ?string $value = null): self
    {
        $WHERE = $this->process_condition_stmt($column,$operator_or_value,$value);

        return $this->clause_array(" AND $WHERE");
    }

    /**
     * @deprecated use warp
     * @see wrap
     */
    final public function bracket(callable $where_callback, ?string $prepend = null): SQL
    {
        return $this->wrap($prepend, $where_callback);
    }

    /**
     * Wraps a condition around a parenthesis `()` popularly known as bracket
     * Currently only supports WHERE statements.
     * @param 'and'|'or'|'AND'|'OR'|'' $prepend
     * @param callable(SQL):(void|SQL) $fn All orm instructions to execute inside the bracket
     * @return SQL
     */
    final public function wrap(
        #[ExpectedValues(["and", "or", "AND", "OR", ""])] string $prepend,
        callable $fn
    ) : SQL
    {
        $this->clause_string_for_bracket(" " . strtoupper($prepend) . " (");

        if($this->using_wrap)
            $this->wrap_index++;

        $this->using_wrap = true;
        $fn($this);

        if($this->wrap_index == 0)
            $this->using_wrap = false;

        if($this->wrap_index > 0)
            $this->wrap_index--;

        $this->clause_string_for_bracket(")");

        if(!$this->using_wrap)
            return $this->clause_array(trim(implode("", $this->cached_options[self::$current_index]['clause_string'])));

        return $this;
    }

    /**
     * @param string $clause
     * @return SelectorOOP|SQL
     * @deprecated Making it a private method in version 0.7.0
     */
    final public function clause(string $clause): self
    {
        return $this->store_vars('clause', $clause);
    }

    private function clause_agr(string $prepend, string $column, string $operator, array $values): self
    {
        $clause_arr = $this->cached_options[self::$current_index]['clause_agr'] ?? [];

        $clause_arr[] = [
            "prepend" => $prepend,
            "column" => $column,
            "operator" => $operator,
            "values" => $values
        ];

        return $this->store_vars('clause_agr', $clause_arr);
    }

    private function clause_array(string $clause): self
    {
        if($this->using_wrap) {
            $this->clause_string_for_bracket($clause);
            return $this;
        }

        $clause_arr = $this->cached_options[self::$current_index]['clause_array'] ?? [];

        $clause_arr[] = $clause;

        return $this->store_vars('clause_array', $clause_arr);
    }

    private function clause_string_for_bracket(string $clause): self
    {
        $clause_arr = $this->cached_options[self::$current_index]['clause_string'] ?? [];

        $clause_arr[] = $clause;

        return $this->store_vars('clause_string', $clause_arr);
    }

    private function set_where(): void
    {
        if(@$this->cached_options[self::$current_index]['has_used_where'])
            return;

        $this->store_vars('has_used_where', true);
    }

    /**
     * Instruct the ORM to run this function on each entry of the record when pooling from the DB
     * @param callable(mixed):mixed $function
     * @return SelectorOOP|SQL
     */
    final public function fun(callable $function): self
    {
        return $this->store_vars('fun', $function);
    }

    /**
     * @see fun
     */
    final public function each(callable $function): self
    {
        return $this->fun($function);
    }

    /**
     * Display the SQL query and kill execution
     * @return SelectorOOP|SQL
     */
    final public function debug(): self
    {
        return $this->store_vars('debug', true);
    }

    /**
     * Some functions which use the `->select()` method internally first send a `->count_row()` query;
     * This method debugs that count query
     * @return SQL|SelectorOOP
     */
    final public function debug_deep(): self
    {
        return $this->store_vars('debug_deep', true);
    }

    /**
     * This debug  method is specifically for select query. When using regular `->debug()` method, you'll notice some part
     * of the query is missing, like LIMIT. This method displays all hidden parts of the query.
     * @return SelectorOOP|SQL
     */
    final public function debug_full(): self
    {
        return $this->store_vars('debug_full', true);
    }

    /**
     * Instructs the ORM to catch any error it encounters, log but don't display
     * @return SelectorOOP|SQL
     */
    final public function catch(): self
    {
        return $this->store_vars('catch', true);
    }

    final public function just_exec(): self
    {
        return $this->store_vars('return_as', OrmReturnType::EXECUTION);
    }

    /**
     * Handles conflict when inserting into a table with unique columns
     *
     * @param array<int,string> $unique_columns
     * @param array<int,string> $update_columns
     * @param "UPDATE"|"IGNORE"|"REPLACE"|"NOTHING" $action
     * @param string|null $constraint a unique constraint name created by the database admin or developer
     */
    final public function on_conflict(
        array $unique_columns = [],
        array $update_columns = [],
        #[ExpectedValues(["UPDATE", "IGNORE", "REPLACE", "NOTHING"])] string $action = "UPDATE",
        ?string $constraint = null,
    ): SQL
    {
        return $this->store_vars('on_conflict', [
            "unique_columns" => $unique_columns,
            "update_columns" => $update_columns,
            "action" => strtoupper($action),
            "constraint" => $constraint,
        ]);
    }

    /**
     * Used to bind values to placeholders in a query. This accepts a regular non-associative array
     *
     * @param array $num_array
     */
    final public function bind_num(array $num_array): SQL
    {
        if(LayArray::any($num_array, fn($v,$i) => is_string($i)))
            $this->oop_exception("`->bind_num()` method accepts numbered index only. If you wish to use named index, use `->bind_assoc`");

        return $this->store_vars('bind_num', $num_array);
    }

    /**
     * Used to bind values to placeholders in a query. This accepts an associative array
     *
     * @param array $assoc_array
     */
    final public function bind_assoc(array $assoc_array): SQL
    {
        if(LayArray::any($assoc_array, fn($v,$i) => is_int($i)))
            $this->oop_exception("`->bind_assoc()` method accepts stringed index only. If you wish to use numbered index, use `->bind_num`");

        return $this->store_vars('bind_assoc', $assoc_array);
    }

    /**
     * Group the result of a select query by a column.
     *
     * This is useful when you have a result with duplicate columns/values;
     * group will pick one and represent the values by that group.
     *
     * @link https://www.w3schools.com/sql/sql_groupby.asp
     *
     * @param string $columns Comma separated columns
     */
    final public function group(string $columns): SQL
    {
        return $this->store_vars('group', $columns);
    }

    /**
     * An alternative to WHERE. It can be used to filter or aggregate a result according to a condition.
     *
     * @link https://www.w3schools.com/sql/sql_having.asp
     *
     * @param string $column
     * @param string|null $operator_or_value
     * @param string|null $value
     * @return SQL
     */
    final public function having(string $column, ?string $operator_or_value = null, ?string $value = null): SQL
    {
        $condition = $this->process_condition_stmt($column,$operator_or_value,$value);

        return $this->store_vars('having', ["condition" => $condition,], true);
    }

    /**
     * @see having
     */
    final public function or_having(string $column, ?string $operator_or_value = null, ?string $value = null): SQL
    {
        $condition = "OR " . $this->process_condition_stmt($column,$operator_or_value,$value);

        return $this->store_vars('having', ["condition" => $condition,], true);
    }

    /**
     * @see having
     */
    final public function and_having(string $column, ?string $operator_or_value = null, ?string $value = null): SQL
    {
        $condition = "AND " . $this->process_condition_stmt($column,$operator_or_value,$value);

        return $this->store_vars('having', ["condition" => $condition,], true);
    }

    /**
     * Search a json column directly with the ORM
     * @param string $column
     * @param string $value
     * @param 'OR'|'or'|'AND'|'AND'|null $prepend
     * @return SelectorOOP|SQL
     */
    final public function json_contains(string $column, string $value, ?string $prepend = null): self
    {
        $contain = match (self::get_driver()) {
            default => "EXISTS (SELECT 1 FROM json_each($column) WHERE value = '$value')",
            OrmDriver::MYSQL => "JSON_CONTAINS($column, '\"$value\"', '$')",
            OrmDriver::POSTGRES => "$column @> '[\"$value\"]'"
        };

        return $this->clause_array(" $prepend " . $this->process_condition_stmt($contain,null, null));
    }

    /**
     * Directly update a json_column value instead of decoding updating and re-encoding
     * @param array $json
     * @param bool $auto_create
     * @return array[]
     */
    final public function json_column(array $json, bool $auto_create = true): array
    {
        return [ "@lay_json@" => ["json" => $json, "auto_create" => $auto_create] ];
    }

    /**
     * Sorts the result of a select query by a column and by an ascending or descending order.
     *
     * @param string $column
     * @param string $order
     */
    final public function sort(string $column, #[ExpectedValues(['ASC', 'asc', 'DESC', 'desc'])] string $order = "ASC"): SQL
    {
        return $this->store_vars('sort', ["sort" => $column, "type" => $order,], true);
    }

    /**
     * Select a range of values from a column.
     *
     * This is very useful when you're trying to implement a date range
     *
     * @param string $column
     * @param string $start
     * @param string $end
     * @param bool $fmt_to_date
     * @param bool $allow_null
     */
    final public function between(string $column, string $start, string $end, bool $fmt_to_date = true, bool $allow_null = true): SQL
    {
        return $this->store_vars('between', ["col" => $column, "start" => $start, "end" => $end, "format" => $fmt_to_date, "allow_null" => $allow_null]);
    }

    /**
     * Limit the result by a value, and by a page number.
     *
     * This is very useful for pagination.
     *
     * ### You can get the select metadata by calling `->get_select_metadata()` which returns an array
     *
     * @param int $max_result Specify query result limit
     * @param int $page_number Specifies the page batch based on the limit
     */
    final public function limit(int $max_result, int $page_number = 1): SQL
    {
        if($page_number < 1) $page_number = 1;
        if($max_result < 1) $max_result = 1;

        return $this->store_vars('limit', ["index" => $page_number, "max_result" => $max_result]);
    }

    /**
     * Tell the orm to not return null or false.
     *
     * If it's a select query, and it's empty, instead of returning a null, it will return an empty array.
     * If it's an update query and the record was not updated, instead of returning false, it will return true.
     *
     * Note: When there is an error, it will still throw an exception. This doesn't prevent that.
     * @return SQL|SelectorOOP
     */
    final public function not_empty(): self
    {
        $this->no_null();
        return $this->no_false();
    }

    /**
     * @see not_empty
     */
    final public function no_null(): SQL
    {
        return $this->store_vars('can_be_null', false);
    }

    /**
     * @see not_empty
     */
    final public function no_false(): SQL
    {
        return $this->store_vars('can_be_false', false);
    }

    /**
     * You can use this to instruct the orm to return a generator instead of an array;
     * then you can yield the result as needed.
     */
    final public function use_generator(): SQL
    {
        return $this->store_vars('return_as', OrmReturnType::GENERATOR);
    }

    /**
     * Instruct the ORM to loop through the result and return an associative array of results.
     *
     * @return array|null
     */
    final public function loop_assoc(): ?array
    {
        $this->loop();
        $this->assoc();
        return $this->select();
    }

    /**
     * Instruct the ORM to loop through the result and return a multidimensional array of results.
     */
    final public function loop(): SQL
    {
        return $this->store_vars('loop', true);
    }

    /**
     * Instruct the ORM to return an associative array of results.
     */
    final public function assoc(): SQL
    {
        return $this->store_vars('fetch_as', OrmReturnType::ASSOC);
    }

    /**
     * Instruct the ORM to loop through the result and return a multidimensional array of results that isn't associative.
     *
     * @return array|null
     */
    final public function loop_row(): ?array
    {
        $this->loop();
        $this->row();
        return $this->select();
    }

    /**
     * Instruct the ORM to return a multidimensional array of results that isn't associative.
     */
    final public function row(): SQL
    {
        return $this->store_vars('fetch_as', OrmReturnType::NUM);
    }

    /**
     * Instruct the ORM to return a single row of results.
     *
     * @param string|array<string, string|int|null|bool> $columns
     * @return bool|array
     */
    final public function then_insert(string|array $columns): bool|array
    {
        $this->column($columns);
        return $this->insert();
    }

    /**
     * This is used to specify the columns to be selected, inserted or updated.
     * Accepts a column name or an array of column names with values.
     *
     * @param string|array<string, string|int|null|bool> $cols
     */
    final public function column(string|array $cols): SQL
    {
        return $this->store_vars('columns', $cols);
    }

    /**
     * Update query
     * @return bool
     */
    final public function then_update(): bool
    {
        return $this->edit();
    }

    /**
     * Select query with as assoc and not null
     *
     *
     * @return Generator|array|null
     */
    final public function then_select(): Generator|array|null
    {
        $this->no_null();
        $this->assoc();
        return $this->select();
    }

    private function store_vars(string $key, mixed $value, $id1 = null, $id2 = null): SQL
    {
        $index = max(self::$current_index, 0);

        if ($id1 === true)
            $this->cached_options[$index][$key][] = $value;

        elseif ($id1 && !$id2)
            $this->cached_options[$index][$key][$id1] = $value;

        elseif ($id1 && $id2)
            $this->cached_options[$index][$key][$id1][$id2] = $value;

        else
            $this->cached_options[$index][$key] = $value;

        return $this;
    }

    /**
     * Temporarily load `$this->cached_options` for a quick operation, unload data and free the memory on done.
     * This method is used by `$this->select()` when `->limit()` is sent
     * @param array $vars
     * @param callable $temporary_fn
     * @return mixed
     */
    protected function _store_vars_temporarily(array $vars, callable $temporary_fn): mixed
    {
        self::$current_index = 969;
        $this->cached_options[self::$current_index] = $vars;

        if(isset($vars['debug_deep']))
            $this->cached_options[self::$current_index]['debug'] = true;

        $data = $temporary_fn();

        unset($this->cached_options[969]);

        return $data;
    }

    protected function get_vars(): array
    {
        $r = $this->cached_options[self::$current_index];
        unset($this->cached_options[self::$current_index]);
        self::$current_index -= 1;

        if (empty($r))
            $this->oop_exception("No variable passed to ORM. At least `table` should be passed");

        return $r;
    }

    protected function oop_exception(string $message, $exception = null): void
    {
        Exception::new()->use_exception("SQL_OOP::ERR", $message, exception: $exception);
    }

}