<?php

namespace BrickLayer\Lay\Orm\Traits;

use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use Exception;
use Generator;

trait SelectorOOPCrud
{
    private mixed $saved_result;

    final public function get_result(): mixed
    {
        return $this->saved_result ?? null;
    }

    final public function last_item(?string $column_to_check = null): array
    {
        $d = $this->get_vars();
        $d['can_be_null'] = false;
        $d['clause'] = $d['clause'] ?? "";
        $sort = $d['sort'] ?? null;
        $d['columns'] = $d['columns'] ?? $d['values'] ?? "*";

        if (!isset($d['table']))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        $column_to_check = $column_to_check ?? $d['table'] . ".id";
        $column_to_check = "ORDER BY $column_to_check DESC";

        if ($sort) {
            $str = "";

            foreach ($sort as $s) {
                $str .= $s['sort'] . " " . $s['type'] . ", ";
            }

            $column_to_check = "ORDER BY " . rtrim($str, ", ");
        }

        return $this->capture_result(
            [$this->query("SELECT {$d['columns']} FROM {$d['table']} {$d['clause']} $column_to_check LIMIT 1", $d), $d],
        );
    }

    /**
     * Inserts to the database. Returns the inserted row if it detects an id column;
     * Otherwise it returns a true on success and false on fail
     * @param array|null $column_and_values
     * @param bool $return_object if true and `$column_and_values` contains an id column, it returns the inserted object
     * @return bool|array
     */
    final public function insert(?array $column_and_values = null, bool $return_object = false): bool|array
    {
        $d = $this->get_vars();
        $column_and_values = $column_and_values ?? $d['values'] ?? $d['columns'];
        $table = $d['table'] ?? null;
        $clause = $d['clause'] ?? null;
        $is_mysql = self::get_driver() == OrmDriver::MYSQL;

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        if (is_array($column_and_values)) {
            $columns = "";
            $values = "";

            try {
                foreach ($column_and_values as $k => $c) {
                    $c = Escape::clean($c, EscapeType::TRIM_ESCAPE);

                    if($c === null) {
                        if($is_mysql)
                            $values .= "`$k`=NULL,";
                        else {
                            $columns .= "\"$k\",";
                            $values .= "NULL,";
                        }

                        continue;
                    }

                    if($k == 'id') {
                        $insert_id = $c;

                        if(trim(strtolower($c)) == 'uuid()')
                            $insert_id = $this->uuid();

                        $c = $insert_id;
                    }

                    // If value `$c` is not a function like uuid(), timestamp(), etc; enclose it quotes
                    if (!preg_match("/^[a-zA-Z]+\([^)]*\)$/", $c))
                        $c = "'$c'";

                    if($is_mysql)
                        $values .= "`$k`=$c,";
                    else {
                        $columns .= "\"$k\",";
                        $values .= "$c,";
                    }

                }
            } catch (Exception $e) {
                $this->oop_exception("Error occurred when trying to insert into a DB: " . $e->getMessage(), $e);
            }

            if($is_mysql)
                $column_and_values = rtrim($values, ",");
            else {
                $columns = "(" . rtrim($columns, ",") . ")";
                $values = "(" . rtrim($values, ",") . ")";
                $column_and_values = "$columns VALUES $values";
            }
        }

        $d['query_type'] = OrmQueryType::INSERT;

        if($is_mysql)
            $column_and_values = "SET $column_and_values";

        $table = self::escape_identifier($table);

        $op = $this->capture_result(
            [$this->query("INSERT INTO $table $column_and_values $clause", $d) ?? false, $d],
            'bool',
        );

        if($return_object && isset($insert_id)) {
            $id = self::escape_identifier("id");
            $table = self::escape_identifier($table);

            return $this->query("SELECT * FROM $table WHERE $id='$insert_id'", [
                'query_type' => OrmQueryType::SELECT,
                'loop' => false,
                'can_be_null' => false,
            ]);
        }

        return $op;
    }

    final public function insert_raw(): bool
    {
        $d = $this->get_vars();
        $columns = $d['columns'] ?? null;
        $values = $d['values'] ?? null;
        $clause = $d['clause'] ?? null;
        $table = $d['table'] ?? null;

        if (empty($columns))
            $this->oop_exception("You did not initialize the `columns`. Use the `->column(String)` method like this: `->column('id,name')`");

        if (empty($values))
            $this->oop_exception("You did not initialize the `values`. Use the `->value(String)` method. Example: `->value(\"(1, 'user name'), (2, 'another user name')\")`");

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        $columns = rtrim($columns, ",");

        if (str_starts_with($values, "("))
            $values = "VALUES" . rtrim($values, ",");

        $d['query_type'] = OrmQueryType::INSERT;

        $table = self::escape_identifier($table);

        return $this->capture_result(
            [$this->query("INSERT INTO $table ($columns) $values $clause", $d) ?? false, $d],
            'bool'
        );
    }

    final public function edit(): bool
    {
        $d = $this->get_vars();
        $values = $d['values'] ?? $d['columns'] ?? "NOTHING";
        $clause = $d['clause'] ?? null;
        $table = $d['table'] ?? null;

        if ($values === "NOTHING")
            $this->oop_exception("There's nothing to update, please use the `column` or `value` method to rectify pass the columns to be updated");

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        if (is_array($values)) {
            $cols = "";

            try {
                foreach ($values as $k => $c) {
                    $c = Escape::clean($c, EscapeType::TRIM_ESCAPE);
                    $k = self::escape_identifier($k);
                    $cols .= $c == null ? "$k=NULL," : "$k='$c',";
                }
            } catch (Exception $e) {
                $this->oop_exception("Error occurred when trying to update a DB", $e);
            }

            $values = rtrim($cols, ",");
        }

        if (!empty($d['switch'] ?? null)) {
            $case_value = "";
            $clause = !$clause ? "" : $clause . " AND ";

            foreach ($d['switch'] as $k => $match) {
                $case = "";
                $case_list = "";
                foreach ($d['case'][$k] as $j => $c) {
                    $case .= "WHEN '$j' THEN $c ";
                    $case_list .= "'$j',";
                }

                $match['column'] = self::escape_identifier($match['column']);
                $match['switch'] = self::escape_identifier($match['switch']);

                $case_list = "(" . rtrim($case_list, ",") . ")";
                $case_value .= "{$match['column']} = CASE {$match['switch']} $case END,";
                $clause .= " {$match['switch']} IN $case_list AND";
            }

            $values = $values . ",";
            $values .= rtrim($case_value, ",");
            $clause = rtrim($clause, " AND");
        }

        $d['query_type'] = OrmQueryType::UPDATE;
        $table = self::escape_identifier($table);

        return $this->capture_result(
            [$this->query("UPDATE $table SET $values $clause", $d), $d],
            'bool'
        );
    }

    final public function update() : bool { return $this->edit(); }

    final public function select(?array $__Internal__ = null): array|null|Generator|\mysqli_result
    {
        $d = $__Internal__ ?? $this->get_vars();
        $table = $d['table'] ?? null;
        $group = $d['group'] ?? null;
        $having = $d['having'] ?? null;
        $sort = $d['sort'] ?? null;
        $limit = $d['limit'] ?? null;
        $clause = $d['clause'] ?? "";
        $cols = $d['values'] ?? $d['columns'] ?? "*";
        $between = $d['between'] ?? null;
        $between_allow_null = true;
        $d['query_type'] = OrmQueryType::SELECT;
        $d['fetch_as'] ??= OrmReturnType::ASSOC;
        $can_be_null = $d['can_be_null'] ?? true;
        $return_type = $can_be_null ? "array|object|null" : "array|object";

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        if($between) {
            $between['start'] = $between['format'] ? date("Y-m-d 00:00:00", strtotime($between['start'])) : $between['format'];
            $between['end'] = $between['format'] ? date("Y-m-d 23:59:59", strtotime($between['end'])) : $between['format'];
            $between_allow_null = $between['allow_null'] ?? true;
            $between = $between['col'] . " BETWEEN '" . $between['start'] . "' AND '" . $between['end'] . "'";

            $clause = $clause ? $clause . " AND ($between) " : "WHERE " . $between;
        }

        if ($group) {
            $str = "";

            foreach ($group as $g) {
                $str .= $g['condition'] . ", ";
            }

            $clause .= " GROUP BY " . rtrim($str, ", ");
        }

        if ($having) {
            $str = "";

            foreach ($having as $h) {
                $str .= $h['condition'] . ", ";
            }

            $clause .= " HAVING " . rtrim($str, ", ");
        }

        if ($sort) {
            $str = "";

            foreach ($sort as $s) {
                $str .= $s['sort'] . " " . $s['type'] . ", ";
            }

            $clause .= " ORDER BY " . rtrim($str, ", ");
        }

        if ($limit && !isset($d['debug'])) {
            $current_queue = $limit['index'];
            $result_per_queue = $limit['max_result'];

            $count = $this->_store_vars_temporarily(
                $d,
                fn() => ceil(($this->count_row("*") / $result_per_queue))
            );


            // cut off request if we've gotten to the last record set
            if ($current_queue > $count)
                return @$d['can_be_null'] ? null : [];

            $current_result = (max($current_queue, 1) - 1) * $result_per_queue;

            $clause .= " LIMIT $current_result, $result_per_queue";
        }

        if (isset($d['join']))
            $clause = $this->_join($d) . $clause;

        $clause = $this->bind_param($clause, $d);

        $rtn = $this->capture_result(
            [$this->query("SELECT $cols FROM $table $clause", $d), $d],
            $return_type
        );

        if(empty($rtn) && !$between_allow_null) {
            unset($d['between']);
            $d['limit']['index'] ??= 1;
            $d['limit']['max_result'] ??= 100;

            return $this->select($d);
        }

        return $rtn;
    }

    final public function count_row(?string $column = null, ?string $where = null): int
    {
        $d = $this->get_vars();
        $col = $column ?? "*";

        $where = $where ? "WHERE $where" : ($d['clause'] ?? null);
        $table = $d['table'] ?? null;

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        $d['query_type'] = OrmQueryType::COUNT;

        $where = $this->_join($d) . $where;

        return $this->capture_result(
            [$this->query("SELECT COUNT($col) FROM $table $where", $d), $d],
            'int'
        );
    }

    final public function delete(?string $where = null, bool $delete_all_records = false): bool
    {
        $d = $this->get_vars();

        if(empty($where) && @empty($d['clause']))
            $this->oop_exception("You cannot delete without a clause. Use the `->clause(String)` or `->where(String)` to indicate a clause. If you wish to delete without a clause, then use the `->query(String)` method to construct your query");

        $d['clause'] = $where ? "WHERE $where" : $d['clause'];
        $d['query_type'] = OrmQueryType::DELETE;
        $table = $d['table'] ?? null;

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        if (empty($d['clause']) and !$delete_all_records)
            $this->oop_exception("You didn't specify a clause for your hard delete statement. If you wish to delete all the records on the table, then update the `delete_all_records` argument on the `->delete()` method");

        return $this->capture_result(
            [$this->query("DELETE FROM $table {$d['clause']}", $d), $d],
            'bool'
        );
    }

    private function capture_result(array $result_and_opt, string $return_type = 'array'): mixed
    {
        $catch_error = $result_and_opt[1]['catch'] ?? false;

        $this->saved_result = $result_and_opt[0];
        $return_as = $result_and_opt[1]['return_as'] ?? OrmReturnType::RESULT;

        if ($return_as != OrmReturnType::GENERATOR && $this->saved_result instanceof Generator)
            $this->saved_result = iterator_to_array($this->saved_result);

        $types = explode("|", $return_type);
        $last_index = count($types) - 1;

        foreach ($types as $i => $type) {
            $x = $this->match_type($type, $i == $last_index, $catch_error);

            if ($x == "__IGNORE__")
                continue;

            if ($x == "__MATCHED__")
                break;

            return $x;
        }

        return $this->saved_result;
    }

    private function match_type(string $return_type, bool $last_index, bool $catch_error): mixed
    {
        $test_type = call_user_func("is_$return_type", $this->saved_result);

        if ($test_type)
            return "__MATCHED__";

        if (!$last_index)
            return "__IGNORE__";

        $type = gettype($this->saved_result);

        if ($type == "object")
            $type = "mysqli_object";

        $return_type = strtoupper($return_type);

        if (!$catch_error)
            $this->oop_exception("invalid return type received from query. Got [<b>$type</b>] instead of [<b>$return_type</b>]");

        return match ($return_type) {
            default => [],
            'STRING' => '',
            'BOOL' => false,
            'INT' => 0,
            'NULL' => null,
        };
    }

    private function bind_param(string $string, array $data) : string
    {
        $bind_num = $data['bind_num'] ?? null;
        $bind_assoc = $data['bind_assoc'] ?? null;

        if($bind_num) {
            foreach ($bind_num as $b) {
                if(gettype($b) != "integer")
                    $b = "'$b'";

                $string = preg_replace("~\?~", "$b", $string, 1);
            }
        }

        if($bind_assoc) {
            foreach ($bind_assoc as $k => $b) {
                if(gettype($b) != "integer")
                    $b = "'$b'";

                $k = ":" . ltrim($k, ":");

                $string = preg_replace("~($k)~", "$b", $string, 1);
            }
        }

        return $string;
    }

    private function _join(array $d): string
    {
        if (!isset($d['join']))
            return "";

        $join_query = "";

        foreach ($d['join'] as $k => $joint) {
            $on = $d['on'][$k];
            $join = [
                "table" => $joint['table'],
                "type" => match (strtolower($joint['type'] ?? "")) {
                    "left", "inner", "right" => strtoupper($joint['type']),
                    default => "",
                },
                "on" => [$on['child_table'], $on['parent_table']],
            ];

            $join_query .= "{$join['type']} JOIN {$join['table']} ON {$join['on'][0]} = {$join['on'][1]} ";
        }

        return $join_query;
    }

}
