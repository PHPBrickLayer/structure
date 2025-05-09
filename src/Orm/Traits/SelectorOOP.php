<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm\Traits;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\SQL;
use Generator;
use JetBrains\PhpStorm\ExpectedValues;
use mysqli_result;
use PgSql\Result;
use SQLite3Result;

trait SelectorOOP
{
    private static int $current_index = 0;
    private array $cached_options = [];
    private bool $using_bracket = false;

    public static function escape_identifier(string $identifier) : string
    {
        $identifier = trim($identifier, "`\"");

        $iden = explode(".", $identifier, 2);

        if(self::get_driver() == OrmDriver::MYSQL) {
            if(!isset($iden[1]))
                return "`$identifier`";

            return "`$iden[0]`.`$iden[1]`";
        }

        if(!isset($iden[1]))
            return "\"$identifier\"";

        return "\"$iden[0]\".\"$iden[1]\"";
    }

    /**
     * Forms a search query based on a relevance scale
     *
     * @param string $query
     * @param array $columns
     * @param string $select_as by default, it returns its result as relevance, so you can sort as that
     * @param array $filter_list List of filler words than need to be removed
     * @return string
     * @example relevance_query (
     *  "Egypt minister",
     *  [
     *      "blogs.title" => [
     *          'full' => 10,
     *          'keyword' => 8
     *      ],
     *      "blogs.subtitle" => [
     *          'full' => 8,
     *          'keyword' => 5
     *      ],
     *      "blogs.tags" => 3,
     *      "blogs.keyword" => 2,
     * ]);
     */
    final public function relevance_query(
        string $query,
        array $columns,
        string $select_as = "relevance",
        array $filter_list = ["in","it","a","the","of","or","I","you","he","me","us","they","she","to","but","that","this","those","then"]
    ) : string
    {
        $filter_words = /**
         * @return string[]
         *
         * @psalm-return list<string>
         */
            function ($query) use ($filter_list) : array {
                $words = [];

                $c = 0;

                foreach(explode(" ", trim($query)) as $key){
                    if (in_array($key, $filter_list))
                        continue;

                    $words[] = $key;

                    if ($c > 14)
                        break;

                    $c++;
                }

                return $words;
            };

        $query = trim($query);

        if (mb_strlen($query) === 0)
            return "";

        $keywords = $filter_words($query);

        $format = function($token, $col, $score, $op = "LIKE"): string {
            $op = $op ? strtolower($op) : null;

            if($op == "=")
                $op = "='$token'";
            else
                $op = "LIKE '%$token%'";

            return "if ($col $op, $score, 0) + ";
        };

        $sql_text = "";

        foreach ($columns as $col => $rule) {
            $esc_query = Escape::clean($query, EscapeType::STRIP_TRIM_ESCAPE);

            $sql_text .= "(";

            if (is_array($rule)) {
                $score = $rule['full'] ?? $rule[0] ?? null;
                $sql_text .= $score ? $format($esc_query, $col, $score, $rule['op'] ?? null) : "";
            }
            else {
                $sql_text .= rtrim($format($esc_query, $col, $rule), "+ ") . ") + ";
                continue;
            }

            if(isset($rule['keyword'])) {
                foreach ($keywords as $key) {
                    if (empty($key))
                        continue;

                    $esc_query = Escape::clean($key, EscapeType::STRIP_TRIM_ESCAPE);
                    $sql_text .= $format($esc_query, $col, $rule['keyword'] ?? $rule[1]);
                }
            }

            $sql_text = rtrim($sql_text, "+ ") . ") + ";
        }

        return "( " . rtrim($sql_text, "+ ") . " ) as $select_as";
    }

    /**
     * Get the syntax to calculate the difference between two dates in the database.
     *
     * ## Note
     * If $date_1 is smaller than $date_2, your result will be negative.
     *
     * ## In plain language
     * By default this function is doing this: `$date_1 - $date_2`
     *
     * @example '2026-02-20', '2025-02-20' == 365
     * @param string $date_1
     * @param string $date_2
     * @param bool $invert_arg use this to invert the syntax to place $date_2 first and $date_1 next
     * @return string
     */
    final public function days_diff(string $date_1, string $date_2, bool $invert_arg = false) : string
    {
        if (LayDate::is_valid($date_1)) {
            $date_1 = explode(" ", $date_1)[0]; // Strip the time part, we're only interested in the date
            $date_1 = "'$date_1'";
            $x1_is_col = false;
        }
        else {
            self::escape_identifier($date_1);
            $x1_is_col = true;
        }

        if (LayDate::is_valid($date_2)) {
            $date_2 = explode(" ", $date_1)[0]; // Strip the time part, we're only interested in the date
            $date_2 = "'$date_2'";
            $x2_is_col = false;
        }
        else {
            self::escape_identifier($date_1);
            $x2_is_col = true;
        }

        $x1 = $date_1;
        $x2 = $date_2;

        if($invert_arg) {
            $x1 = $date_2;
            $x2 = $date_1;

            $c1 = $x1_is_col;
            $x1_is_col = $x2_is_col;
            $x2_is_col = $c1;
        }

        $driver = self::get_driver();

        if($driver == OrmDriver::POSTGRES) {
            $x1 = $x1_is_col ? $x1 : "$x1::date";
            $x2 = $x2_is_col ? $x2 : "$x2::date";

            return "(CAST(($x1 - $x2) AS INTEGER))";
        }

        if(OrmDriver::is_sqlite($driver))
            return "(CAST(julianday(date($x1)) - julianday(date($x2)) AS INTEGER))";


        return "(DATEDIFF($x1, $x2))";
    }

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
     * Wraps a condition around a parenthesis `()` popularly known as bracket
     * Currently only supports WHERE statements.
     * @param callable(self):void $where_callback
     * @param null|'and'|'or'|'AND'|'OR' $prepend
     * @return SQL
     */
    final public function bracket(
        callable $where_callback,
        #[ExpectedValues(["and", "or", "AND", "OR"])] ?string $prepend = null,
    ): SQL
    {
        $this->using_bracket = true;
        $where_callback($this);
        $this->using_bracket = false;

        $WHERE = trim(implode("", $this->cached_options[self::$current_index]['clause_string']));
        return $this->clause_array(strtoupper($prepend ?? '') . " ($WHERE)");
    }

    final public function clause(string $clause): self
    {
        return $this->store_vars('clause', $clause);
    }

    private function clause_array(string $clause): self
    {
        if($this->using_bracket) {
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
        return $this->store_vars('group', ["columns" => $columns,]);
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
     * @param string|null $clause
     * @return array|null
     */
    final public function loop_assoc(?string $clause = null): ?array
    {
        if ($clause) $this->clause($clause);
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
     * @param string|null $clause
     * @return array|null
     */
    final public function loop_row(?string $clause = null): ?array
    {
        if ($clause) $this->clause($clause);
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
     * Update query with a clause directly here
     * @param string|null $clause
     * @return bool
     */
    final public function then_update(?string $clause = null): bool
    {
        if ($clause)
            $this->clause($clause);

        return $this->edit();
    }

    /**
     * Select query with a clause directly here
     *
     * @param string|null $clause
     *
     * @return Generator|Result|SQLite3Result|mysqli_result|array|null
     */
    final public function then_select(?string $clause = null): mixed
    {
        if ($clause)
            $this->clause($clause);

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