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
use Closure;
use Generator;
use JetBrains\PhpStorm\ExpectedValues;

trait SelectorOOP
{
    private static int $current_index = 0;
    private array $cached_options = [];

    public static function escape_identifier(string $identifier) : string
    {
        if(self::get_driver() == OrmDriver::MYSQL) {
            $identifier = trim($identifier, "`");
            return "`$identifier`";
        }

        $identifier = trim($identifier, '"');
        return "\"$identifier\"";
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
     * "Egypt minister",
     * [
     *  "blogs.title" => [
     *      'full' => 10,
     *      'keyword' => 8
     *  ],
     * "blogs.subtitle" => [
     *      'full' => 8,
     *      'keyword' => 5
     *      ],
     * "blogs.tags" => 3,
     * "blogs.keyword" => 2,
     * ]);
     */
    final public function relevance_query(
        string $query,
        array $columns,
        string $select_as = "relevance",
        array $filter_list = ["in","it","a","the","of","or","I","you","he","me","us","they","she","to","but","that","this","those","then"]
    ) : string
    {
        $filter_words = function ($query) use ($filter_list) : array {
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

        $format = function($token, $col, $score, $op = "LIKE") {
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
     * @example DATEDIFF('2025-02-20', '2026-02-20') == -365
     * @param string $date_1
     * @param string $date_2
     * @param bool $invert_arg use this to invert the syntax to place $date_2 first and $date_1 next
     * @return string
     * @todo Implement the various shades of date diff according to the supported database drivers
     */
    final public function days_diff(string $date_1, string $date_2, bool $invert_arg = false) : string
    {
        $date_1 = LayDate::is_valid($date_1) ? "'$date_1'" : self::escape_identifier($date_1);
        $date_2 = LayDate::is_valid($date_2) ? "'$date_2'" : self::escape_identifier($date_2);

        if($invert_arg)
            return "DATEDIFF($date_2, $date_1)";

        return "DATEDIFF($date_1, $date_2)";
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

    final public function join(string $join_table, #[ExpectedValues(['right', 'inner', 'left', ''])] string $type = ""): self
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

    final public function where(string $WHERE): self
    {
        return $this->clause("WHERE $WHERE");
    }

    final public function clause(string $clause): self
    {
        return $this->store_vars('clause', $clause);
    }

    final public function fun(Closure $function): self
    {
        return $this->store_vars('fun', $function);
    }

    final public function debug(): self
    {
        return $this->store_vars('debug', true);
    }

    final public function debug_deep(): self
    {
        return $this->store_vars('debug_deep', true);
    }

    final public function catch(): self
    {
        return $this->store_vars('catch', true);
    }

    final public function just_exec(): self
    {
        return $this->store_vars('return_as', OrmReturnType::EXECUTION);
    }

    final public function bind_num(array $num_array): self
    {
        if(@LayArray::some($num_array, fn($v,$i) => is_string($i))[0])
            $this->oop_exception("`->bind_num()` method accepts numbered index only. If you wish to use named index, use `->bind_assoc`");

        return $this->store_vars('bind_num', $num_array);
    }

    final public function bind_assoc(array $assoc_array): self
    {
        if(@LayArray::some($assoc_array, fn($v,$i) => is_int($i))[0])
            $this->oop_exception("`->bind_assoc()` method accepts stringed index only. If you wish to use numbered index, use `->bind_num`");

        return $this->store_vars('bind_assoc', $assoc_array);
    }

    final public function group(string $by): self
    {
        return $this->store_vars('group', ["condition" => $by,], true);
    }

    final public function having(string $condition): self
    {
        return $this->store_vars('having', ["condition" => $condition,], true);
    }

    final public function sort(string $column, #[ExpectedValues(['ASC', 'asc', 'DESC', 'desc'])] string $order = "ASC"): self
    {
        return $this->store_vars('sort', ["sort" => $column, "type" => $order,], true);
    }

    final public function between(string $column, string $start, string $end, bool $fmt_to_date = true, bool $allow_null = true): self
    {
        return $this->store_vars('between', ["col" => $column, "start" => $start, "end" => $end, "format" => $fmt_to_date, "allow_null" => $allow_null]);
    }

    /**
     * @param int $max_result Specify query result limit
     * @param int $page_number Specifies the page batch based on the limit
     * @param string|null $column_to_check
     * @return SelectorOOP|SQL
     */
    final public function limit(int $max_result, int $page_number = 1, ?string $column_to_check = null): self
    {
        return $this->store_vars('limit', ["index" => $page_number, "max_result" => $max_result, "column" => $column_to_check,]);
    }

    final public function not_empty(): self
    {
        $this->no_null();
        return $this->no_false();
    }

    final public function no_null(): self
    {
        return $this->store_vars('can_be_null', false);
    }

    final public function no_false(): self
    {
        return $this->store_vars('can_be_false', false);
    }

    final public function use_generator(): self
    {
        return $this->store_vars('return_as', OrmReturnType::GENERATOR);
    }

    final public function loop_assoc(?string $clause = null): ?array
    {
        if ($clause) $this->clause($clause);
        $this->loop();
        $this->assoc();
        return $this->select();
    }

    final public function loop(): self
    {
        return $this->store_vars('loop', true);
    }

    final public function assoc(): self
    {
        return $this->store_vars('fetch_as', OrmReturnType::ASSOC);
    }

    final public function loop_row(?string $clause = null): ?array
    {
        if ($clause) $this->clause($clause);
        $this->loop();
        $this->row();
        return $this->select();
    }

    final public function row(): self
    {
        return $this->store_vars('fetch_as', OrmReturnType::NUM);
    }

    final public function then_insert(string|array $columns): bool|array
    {
        $this->column($columns);
        return $this->insert();
    }

    final public function column(string|array $cols): self
    {
        return $this->store_vars('columns', $cols);
    }

    final public function then_update(?string $clause = null): bool
    {
        if ($clause)
            $this->clause($clause);

        return $this->edit();
    }

    final public function then_select(?string $clause = null): array|Generator
    {
        if ($clause)
            $this->clause($clause);

        $this->no_null();
        $this->assoc();
        return $this->select();
    }

    private function store_vars(string $key, mixed $value, $id1 = null, $id2 = null): self
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
    private function _store_vars_temporarily(array $vars, callable $temporary_fn): mixed
    {
        self::$current_index = 969;
        $this->cached_options[self::$current_index] = $vars;

        if(isset($vars['debug_deep']))
            $this->cached_options[self::$current_index]['debug'] = true;

        $data = $temporary_fn();

        unset($this->cached_options[969]);

        return $data;
    }

    private function get_vars(): array
    {
        $r = $this->cached_options[self::$current_index];
        unset($this->cached_options[self::$current_index]);
        self::$current_index -= 1;

        if (empty($r))
            $this->oop_exception("No variable passed to ORM. At least `table` should be passed");

        return $r;
    }

    private function oop_exception(string $message, $exception = null): void
    {
        Exception::new()->use_exception("SQL_OOP::ERR", $message, exception: $exception);
    }

}