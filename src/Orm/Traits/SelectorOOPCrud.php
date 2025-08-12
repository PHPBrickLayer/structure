<?php

namespace BrickLayer\Lay\Orm\Traits;

use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\SQL;
use Exception;
use Generator;

trait SelectorOOPCrud
{
    private mixed $saved_result;

    /**
     * @var array{
     *     page :  int,
     *     per_page :  int,
     *     page_count :  int,
     *     total_count :  int,
     * }
     */
    protected array $select_metadata;

    final public function get_result(): mixed
    {
        return $this->saved_result ?? null;
    }

    final public function get_select_metadata(): array
    {
        return $this->select_metadata;
    }

    final public function last_item(?string $column_to_check = null): array
    {
        $d = $this->get_vars();
        $d['can_be_null'] = false;
        $d['clause'] = $this->parse_clause($d) ?? '';
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
            [$this->query(/** @lang text */ "SELECT {$d['columns']} FROM {$d['table']} {$d['clause']} $column_to_check LIMIT 1", $d), $d],
        );
    }

    /**
     * Handles insert conflict
     *
     * @param array $orm_vars
     * @param string $table
     * @param string $column_and_values
     * @param string|null $clause
     *
     * @return null|string
     */
    private function handle_insert_conflict(array $orm_vars, string $table, string $column_and_values, ?string $clause = null) : string|null
    {
        $conflict = $orm_vars['on_conflict'] ?? null;

        if(!$conflict) return null;

        $driver = self::get_driver();

        if($driver == OrmDriver::MYSQL) {
            $update = "";

            if($conflict['action'] == 'IGNORE' || $conflict['action'] == 'NOTHING')
                return /** @lang text */ "INSERT IGNORE INTO $table $column_and_values $clause";

            if($conflict['action'] == 'REPLACE')
                return /** @lang text */ "REPLACE INTO $table $column_and_values $clause";

            if(empty($conflict['update_columns']) && empty($conflict['ignore_columns']))
                $this->oop_exception(
                    "OnConflict Error; update_columns and ignore_columns cannot be empty when action is `UPDATE` for $table; you must specify the columns to update or ignore"
                );

            if(in_array($conflict['action'], ['REPLACE', 'UPDATE'], true) && empty($conflict['update_columns'])) {
                $conflict['update_columns'] = explode(",", trim(explode("VALUES", $column_and_values, 2)[0], "( )"));
            }

            foreach ($conflict['update_columns'] as $col) {
                if(in_array($col, $conflict['ignore_columns'], true)) continue;

                $update .= self::escape_identifier($col) . " = VALUES($col),";
            }

            $update = rtrim($update, ",");

            return /** @lang text */ "INSERT INTO $table $column_and_values ON DUPLICATE KEY UPDATE $update $clause;";
        }

        if($conflict['action'] == 'REPLACE' && OrmDriver::is_sqlite($driver))
            return /** @lang text */ "INSERT OR REPLACE INTO $table $column_and_values $clause";

        $unique_cols = !empty($conflict['unique_columns'])  ? implode(",", $conflict['unique_columns']) : null;

        if(!$unique_cols)
            $this->oop_exception(
                "OnConflict Error; Only `REPLACE` actions in MySQL and SQLite can be ran without unique columns"
            );

        if($conflict['action'] == 'IGNORE' || $conflict['action'] == 'NOTHING')
            return /** @lang text */ "INSERT INTO $table $column_and_values ON CONFLICT($unique_cols) DO NOTHING $clause;";

        $update_cols = "";
        $excluded = OrmDriver::is_sqlite($driver) ? "excluded" : "EXCLUDED";

        if($driver == OrmDriver::POSTGRES && in_array($conflict['action'], ['REPLACE', 'UPDATE'], true) && empty($conflict['update_columns'])) {
            $conflict['update_columns'] = explode(",", trim(explode("VALUES", $column_and_values, 2)[0], "( )"));
        }

        if(empty($conflict['update_columns']) && empty($conflict['ignore_columns']))
            $this->oop_exception("OnConflict Error; update_column and ignore_columns cannot both be empty when action is `UPDATE`; one must be filled for $table");

        foreach ($conflict['update_columns'] as $col) {
            $trimmed_col = trim($col,"\"`");

            if(
                $trimmed_col == "id"
                || in_array($trimmed_col, $conflict['ignore_columns'], true)
                || in_array($trimmed_col, $conflict['unique_columns'], true)
            ) continue;

            $update_cols .= self::escape_identifier($col) . " = $excluded.$col,";
        }

        $update_cols = rtrim($update_cols, ",");

        return /** @lang text */ "INSERT INTO $table $column_and_values ON CONFLICT ($unique_cols) DO UPDATE SET $update_cols $clause;";
    }

    private function clean_input(mixed $value) : mixed
    {
        if(!$value) return $value;

        if(is_array($value))
            LayException::throw("Invalid! You cannot pass an array as a value to be inserted into the DB. You must first json_encode it", "ORM::ERR");

        // Avoid double escape if ' or " is found to be escaped already
        if(preg_match('/(\\\\\'|\\\\\")/', $value))
            return $value;

        return Escape::clean($value, EscapeType::TRIM_ESCAPE);
    }

    /**
     * Inserts a single record into the database.
     *
     * This function returns the inserted row if it detects an id column and $return_object is true;
     * Otherwise it returns a true on success and false on fail
     * @param array|null $column_and_values
     * @param bool $return_record if true and `$column_and_values` contains an id column, it returns the inserted object
     * @return bool|array
     */
    final public function insert(?array $column_and_values = null, bool $return_record = false): bool|array
    {
        $d = $this->get_vars();
        $column_and_values = $column_and_values ?? $d['values'] ?? $d['columns'];
        $table = $d['table'] ?? null;
        $clause = $this->parse_clause($d);
        $is_mysql = self::get_driver() == OrmDriver::MYSQL;
        $is_maria_db = $this->get_link()->server_info()['service'] == 'MariaDB';

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        $primary_key = "id";

        if (is_array($column_and_values)) {
            $columns = "";
            $values = "";

            try {
                foreach ($column_and_values as $k => $c) {
                    $c = $this->clean_input($c);

                    if ($c === null) {
                        $k = self::escape_identifier($k);

                        if ($is_mysql)
                            $values .= "$k=NULL,";
                        else {
                            $columns .= "$k,";
                            $values .= "NULL,";
                        }

                        continue;
                    }

                    if($k == 'id' || $k == 'entity_guid') {
                        $insert_id = $c;
                        $primary_key = $k;

                        if(trim(strtolower($c)) == 'uuid()')
                            $insert_id = $this->uuid();

                        $c = $insert_id;
                    }

                    // If value `$c` is not a function like uuid(), timestamp(), etc; enclose it quotes
                    if (!preg_match("/^[a-zA-Z]+\([^)]*\)$/", $c))
                        $c = "'$c'";

                    $k = self::escape_identifier($k);

                    if ($is_mysql)
                        $values .= "$k=$c,";
                    else {
                        $columns .= "$k,";
                        $values .= "$c,";
                    }

                }
            } catch (Exception $e) {
                $this->oop_exception("Error occurred when trying to insert into a DB: " . $e->getMessage(), $e);
            }

            if ($is_mysql)
                $column_and_values = rtrim($values, ",");
            else {
                $columns = "(" . rtrim($columns, ",") . ")";
                $values = "(" . rtrim($values, ",") . ")";
                $column_and_values = "$columns VALUES $values";
            }
        }

        $d['query_type'] = OrmQueryType::INSERT;

        if ($is_mysql)
            $column_and_values = "SET $column_and_values";

        if($is_mysql)
            $is_mysql = !$is_maria_db;

        if($return_record && !$is_mysql ) {
            $clause = "$clause RETURNING *";
            $d['result_on_insert'] = true;
        }

        $table = self::escape_identifier($table);
        $query = $this->handle_insert_conflict($d, $table, $column_and_values, $clause) ?? /** @lang text */
            "INSERT INTO $table $column_and_values $clause";

        $op = $this->capture_result(
            [$this->query($query, $d) ?? false, $d],
            'array|bool',
        );

        if ($is_mysql && $return_record && isset($insert_id)) {
            $id = self::escape_identifier($primary_key);

            return $this->query(/** @lang text */ "SELECT * FROM $table WHERE $id='$insert_id'", [
                'query_type' => OrmQueryType::SELECT,
                'loop' => false,
                'can_be_null' => false,
            ]);

        }

        return $op;
    }

    /**
     * Insert multiple rows
     * @param array $multi_column_and_values
     * @param bool $auto_add_uid Add col `id` to the column entries if it doesn't exist. This does not work for auto_increment col
     * @return bool
     */
    final public function insert_multi(array $multi_column_and_values, bool $auto_add_uid = false): bool
    {
        $d = $this->get_vars();
        $table = $d['table'] ?? null;
        $clause = $this->parse_clause($d);
        $fun = $d['fun'] ?? null;

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        $columns = [];
        $values = "";
        $add_id = function ($val) {
            $insert_id = $val;

            if(trim(strtolower($val)) == 'uuid()')
                $insert_id = $this->uuid();

            return $insert_id;
        };

        try {
            foreach ($multi_column_and_values as $entry) {
                $values .= "(";
                $has_col_id = false;

                if($fun) $entry = $fun($entry);

                foreach ($entry as $col => $val) {

                    if(!isset($columns[$col]))
                        $columns[$col] = self::escape_identifier($col);

                    $val = $this->clean_input($val);

                    if($val === null) {
                        $values .= "NULL,";
                        continue;
                    }

                    if($col == 'id') {
                        $val = $add_id($val);
                        $has_col_id = true;
                    }

                    // If value is not a function like uuid(), timestamp(), etc; enclose it quotes
                    if (!preg_match("/^[a-zA-Z]+\([^)]*\)$/", $val))
                        $val = "'$val'";

                    $values .= "$val,";
                }

                if(!$has_col_id && $auto_add_uid) {
                    if(!isset($columns['id']))
                        $columns['id'] = self::escape_identifier('id');

                    $values .= "'" . $add_id('uuid()') . "',";
                }

                $values = rtrim($values, ",") . "),";
            }
        } catch (Exception $e) {
            $this->oop_exception("Error occurred when trying to insert into a DB", $e);
        }

        $values = rtrim($values, ",");
        $columns = "(" . implode(",", $columns) . ")";
        $column_and_values = "$columns VALUES $values";

        $table = self::escape_identifier($table);
        $d['query_type'] = OrmQueryType::INSERT;

        $query = $this->handle_insert_conflict($d, $table, $column_and_values, $clause) ?? /** @lang text */ "INSERT INTO $table $column_and_values $clause";

        return $this->capture_result(
            [$this->query($query, $d) ?? false, $d],
            'bool',
        );
    }

    final public function edit_multi(array $multi_column_and_values) : bool
    {
        $d = $this->get_vars();
        $table = $d['table'] ?? null;

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        return SQL::new()::scoped_transaction(function () use ($multi_column_and_values, $d, $table) {
            $clause_agr = $d['clause_agr'];
            $fun = $d['fun'] ?? null;

            $table = self::escape_identifier($table);

            $treat_value = function ($val) {
                $val = $this->clean_input($val);

                if($val === null) return "NULL";

                // If value is not a function like uuid(), timestamp(), etc; enclose it quotes
                if (!preg_match("/^[a-zA-Z]+\([^)]*\)$/", $val))
                    $val = "'$val'";

                return "$val";
            };

            foreach ($multi_column_and_values as $index => $entry) {
                $values = "";

                if($fun) $entry = $fun($entry);

                foreach ($entry as $col => $val) {
                    $col = self::escape_identifier($col);

                    $values .= " $col=" . $treat_value($val) . ",";
                }

                $clause = "";

                foreach ($clause_agr as $clu) {
                    $c = $clu;

                    $clause .= $c['prepend'] . " " . self::escape_identifier($c['column']) . " " . $c['operator'] . " " . $treat_value($c['values'][$index]) . " ";
                }

                $values = rtrim($values, ",");

                $success = $this->capture_result(
                    [$this->query(/** @lang text */ "UPDATE $table SET $values $clause", $d), $d],
                    'bool'
                );

                if(!$success) return [
                    'status' => "error",
                    "message" => "Update was not done due to an unknown error"
                ];

            }

            return [
                "status" => "success",
                "data" => true
            ];
        })['data'];
    }

    final public function edit(): bool
    {
        $d = $this->get_vars();
        $values = $d['values'] ?? $d['columns'] ?? "NOTHING";
        $clause = $this->parse_clause($d);
        $table = $d['table'] ?? null;

        if ($values === "NOTHING")
            $this->oop_exception("There's nothing to update, please use the `column` or `value` method to rectify pass the columns to be updated");

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        if (is_array($values)) {
            $cols = "";
            $json_col_fn = function (string $column, array $struct) {
                $driver = self::get_driver();
                $cast = function ($value) {
                    if(is_string($value))
                        $value = '"' . $value . '"';

                    if(is_array($value))
                        $value = json_encode($value);

                    if(is_bool($value))
                        $value = $value ? 'true' : 'false';

                    return $this->clean_input($value);
                };

                if($driver == OrmDriver::MYSQL) {
                    $x = "";

                    foreach ($struct['json'] as $key => $value) {
                        $value = $cast($value);
                        $x .= "'$.$key', $value,";
                    }

                    $x = rtrim($x, ",");

                    return "JSON_SET($column, $x),";
                }

                if($driver == OrmDriver::POSTGRES) {
                    $auto_create = $struct['auto_create'] ? 'true' : 'false';

                    foreach ($struct['json'] as $key => $value) {
                        $value = "'" . $cast($value) . "'::jsonb";

                        $column = "jsonb_set($column, '{" . $key . "}', $value, $auto_create)";
                    }

                    return $column . ",";
                }

                foreach ($struct['json'] as $key => $value) {
                    $value = $cast($value);

                    $column = "json_set($column, '$.$key', json($value))";
                }

                return $column . ",";
            };

            try {
                foreach ($values as $k => $c) {
                    $k = self::escape_identifier($k);

                    // Process JSON column update
                    if(is_array($c) && isset($c['@lay_json@'])) {
                        $cols .= "$k = " . $json_col_fn($k, $c['@lay_json@']);
                        continue;
                    }

                    $c = $this->clean_input($c);
                    $cols .= $c == null ? "$k = NULL," : "$k = '$c',";
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

        $res = $this->query(/** @lang text */ "UPDATE $table SET $values $clause", $d);

        return $this->capture_result(
            [is_bool($res) ? $res : false, $d],
            'bool'
        );
    }

    final public function update() : bool { return $this->edit(); }

    final public function select(?array $__Internal__ = null): array|null|Generator|\mysqli_result|\SQLite3Result|\PgSql\Result
    {
        $d = $__Internal__ ?? $this->get_vars();
        $table = $d['table'] ?? null;
        $group = $d['group'] ?? null;
        $having = $d['having'] ?? null;
        $sort = $d['sort'] ?? null;
        $limit = $d['limit'] ?? null;
        $clause = $this->parse_clause($d) ?? "";
        $columns = $d['values'] ?? $d['columns'] ?? "*";
        $between = $d['between'] ?? null;
        $between_allow_null = true;
        $d['query_type'] = OrmQueryType::SELECT;
        $d['fetch_as'] ??= OrmReturnType::ASSOC;
        $can_be_null = $d['can_be_null'] ?? true;
        $return_type = $can_be_null ? "array|object|null" : "array|object";

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        if($between) {
            $between['start'] = $between['format'] ? date("Y-m-d 00:00:00", strtotime($between['start'])) : $between['start'];
            $between['end'] = $between['format'] ? date("Y-m-d 23:59:59", strtotime($between['end'])) : $between['end'];
            $between_allow_null = $between['allow_null'] ?? true;
            $between = self::escape_identifier($between['col']) . " BETWEEN '" . $between['start'] . "' AND '" . $between['end'] . "'";

            $clause = $clause ? $clause . " AND ($between) " : "WHERE " . $between;
        }

        if ($group) {
            $group = rtrim($group, ",");

            $str = "";
            foreach (explode(",", $group) as $g) {
                $str .= self::escape_identifier($g) . ", ";
            }

            $clause .= " GROUP BY " . rtrim($str, ", ");
        }

        if ($having) {
            $str = "";

            foreach ($having as $h) {
                $str .= $h['condition'] . " ";
            }

            $clause .= " HAVING $str";
        }

        if ($sort) {
            $str = "";

            foreach ($sort as $s) {
                $str .= self::escape_identifier($s['sort']) . " " . $s['type'] . ", ";
            }

            $clause .= " ORDER BY " . rtrim($str, ", ");
        }

        if ($limit && !isset($d['debug'])) {
            $current_queue = $limit['index'];
            $result_per_queue = $limit['max_result'];
            $total_result = 0;

            $count = $this->_store_vars_temporarily(
                $d,
                function() use (&$total_result, $result_per_queue) {
                    $total_result = $this->count_row("*");
                    return ceil($total_result / $result_per_queue);
                }
            );

            $current_result = (max($current_queue, 1) - 1) * $result_per_queue;

            $this->select_metadata = [
                "page" => $current_queue,
                "per_page" => $result_per_queue,
                "page_count" => $count,
                "total_count" => $total_result,
            ];

            // cut off request if we've gotten to the last record set
            if ($current_queue > $count)
                return @$d['can_be_null'] ? null : [];

            $clause .= " LIMIT $result_per_queue OFFSET $current_result";
        }

        if(isset($d['debug_full']))
            $d['debug'] = true;

        if (isset($d['join']))
            $clause = $this->_join($d) . $clause;

        $clause = $this->bind_param($clause, $d);

        $table = self::escape_identifier($table);

        $cols = $columns;

        if(is_array($cols)) {
            $cols = "";

            foreach ($columns as $as => $column) {
                $cols .= "$column AS $as,";
            }

            $cols = rtrim($cols, ",");
        }

        $rtn = $this->capture_result(
            [$this->query(/** @lang text */ "SELECT $cols FROM $table $clause", $d), $d],
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

        $where = $where ? "WHERE $where" : ($this->parse_clause($d) ?? null);
        $table = $d['table'] ?? null;

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        $d['query_type'] = OrmQueryType::COUNT;

        $where = $this->_join($d) . $where;

        return $this->capture_result(
            [$this->query(/** @lang text */ "SELECT COUNT($col) FROM $table $where", $d), $d],
            'int'
        );
    }

    final public function count(?string $column = null) : int
    {
        return $this->count_row($column);
    }

    final public function delete(?string $where = null, bool $delete_all_records = false): bool
    {
        $d = $this->get_vars();
        $d['clause'] = $this->parse_clause($d);

        if(empty($where) && empty($d['clause']))
            $this->oop_exception("You cannot delete without a clause. Use the `->clause(String)` or `->where(String)` to indicate a clause. If you wish to delete without a clause, then `\$delete_all_records` must be true");

        $d['clause'] = $where ? "WHERE $where" : $d['clause'];
        $d['query_type'] = OrmQueryType::DELETE;
        $table = $d['table'] ?? null;

        if (empty($table))
            $this->oop_exception("You did not initialize the `table`. Use the `->table(String)` method like this: `->value('your_table_name')`");

        if (empty($d['clause']) and !$delete_all_records)
            $this->oop_exception("You didn't specify a clause for your hard delete statement. If you wish to delete all the records on the table, then update the `delete_all_records` argument on the `->delete()` method");

        return $this->capture_result(
            [$this->query(/** @lang text */ "DELETE FROM $table {$d['clause']}", $d), $d],
            'bool'
        );
    }

    private function parse_clause(array $data) : ?string
    {
        if(isset($data['clause_array']))
            $data['clause'] = implode(" ", $data['clause_array']);

        return $data['clause'] ?? null;
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

    private function bind_param(string $string, array $data) : string|null
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
            $table = str_replace([" as ", " AS ", "As"], " __AS__ ", $joint['table']);
            $table = explode(" __AS__ ", $table);

            $on = $d['on'][$k];
            $join = [
                "table" => self::escape_identifier($table[0]) . (isset($table[1]) ? " AS $table[1]" : ''),
                "type" => match (strtolower($joint['type'] ?? "")) {
                    "left", "inner", "right" => strtoupper($joint['type']),
                    default => "",
                },
                "on" => [self::escape_identifier($on['child_table']), self::escape_identifier($on['parent_table'])],
            ];

            $join_query .= "{$join['type']} JOIN {$join['table']} ON {$join['on'][0]} = {$join['on'][1]} ";
        }

        return $join_query;
    }

}
