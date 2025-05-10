<?php

namespace BrickLayer\Lay\Libs\Primitives\Traits;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Captcha\Captcha;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadType;
use BrickLayer\Lay\Libs\FileUpload\FileUpload;
use BrickLayer\Lay\Libs\LayCrypt\LayCrypt;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use Closure;
use Exception;


/**
 * @phpstan-type VcmRules array{
 *    required?: bool,
 *    result_is_assoc?: bool,
 *    alias_required?: bool,
 *    clean?: bool|array{
 *        escape: EscapeType|array<int,EscapeType>,
 *      strict: bool,
 *    },
 *    sub_dir?: string,
 *    allowed_types?: array<int, FileUploadExtension>,
 *    allowed_extensions?: array<int, FileUploadExtension>,
 *    max_size?: int,
 *    max_size_in_mb?: float,
 *    new_file_name?: string,
 *    dimension?: array,
 *    upload_storage?: FileUploadStorage,
 *    bucket_url?: string,
 *    upload_handler?: callable,
 *    return_struct?: callable<mixed, string>,
 *    return_schema?: callable<mixed, string>,
 *  }
 */
trait ValidateCleanMap {
    protected static self $VCM_INSTANCE;

    private static array|object|null $_filled_request;
    private static array $_entries = [];
    private static ?array $_errors;
    private static ?bool $_break_validation = false;

    private static ?bool $_required;
    private static ?bool $_alias_required;
    private static array|bool|null $_clean_by_default;
    private static ?string $_sub_dir;
    private static ?array $_allowed_types;
    private static ?int $_max_size;
    private static ?string $_new_file_name;
    private static ?array $_dimension;
    private static ?FileUploadStorage $_upload_storage;
    private static ?string $_bucket_url;
    private static ?closure $_upload_handler;
    private static ?closure $_return_schema;
    private static bool $_result_is_assoc = true;

    private function __add_error(string $field, string $message): false
    {
        static::$_errors[$field] = $message;
        return false;
    }

    private function __get_field(string $key) : mixed
    {
        if(is_array(static::$_filled_request))
            return static::$_filled_request[$key] ?? null;

        return static::$_filled_request->{$key} ?? null;
    }

    /**
     * @return (bool|string)[]
     *
     * @psalm-return array{valid: bool, message: string}
     */
    private function __validate_captcha(?string $value, bool $as_jwt = false, ?string $jwt = null) : array
    {
        if(is_null($value))
            return [
                "valid" => false,
                "message" => "Captcha is required",
            ];

        if ($as_jwt) {
            if(is_null($jwt))
                return [
                    "valid" => false,
                    "message" => "Invalid captcha jwt received. Captcha has been wrongly implemented on the client's side",
                ];

            return Captcha::validate_as_jwt($jwt, $value);
        }

        return Captcha::validate_as_session($value);
    }

    /**
     * Default file upload handler
     *
     * @param string $post_name
     * @param string $new_name
     * @param string $upload_sub_dir
     * @param int $file_limit
     * @param array $extension_list
     * @param array $dimension
     * @param FileUploadStorage|null $storage
     * @param string|null $bucket_url
     * @param FileUploadType|null $upload_type
     * @return array{
     *    uploaded: bool,
     *    dev_error: ?string,
     *    error: ?string,
     *    error_type: ?FileUploadErrors,
     *    upload_type: FileUploadType,
     *    storage: FileUploadStorage,
     *    url: ?string,
     *    size: ?int,
     *    width: ?int,
     *    height: ?int,
     * }
     * @throws Exception
     */
    private function __file_upload_handler(
        string $post_name,
        string $new_name,
        string $upload_sub_dir,
        int $file_limit,
        array $extension_list,
        array $dimension,
        ?FileUploadStorage $storage = FileUploadStorage::BUCKET,
        ?string $bucket_url = null,
        ?FileUploadType $upload_type = null,
        bool $dry_run = false,
    ) : array
    {
        // If dev wishes to use a custom upload handler, it must follow the params list chronologically,
        // and return an array.
        if(isset(static::$_upload_handler)) {
            return static::$_upload_handler->call(
                $this,
                $post_name, $new_name, $upload_sub_dir, $file_limit,
                $extension_list, $dimension, $storage, $bucket_url,
                $upload_type, $dry_run
            );
        }

        // Example of $bucket_url: LayConfig::site_data()->others->bucket_domain
        // "https://wp-content.folsortinvestmentservices.com/"

        $server = LayConfig::server_data();
        $dir = $server->uploads_no_root . $upload_sub_dir;
        $root = $server->root . "web" . DIRECTORY_SEPARATOR;

        $file = (new FileUpload([
            "post_name" => $post_name,
            "new_name" =>  Escape::clean($new_name, EscapeType::P_URL),
            "directory" => $root . $dir,
            "permission" => 0755,
            "file_limit" => $file_limit,
            "storage" => $storage,
            "bucket_path" => str_replace("uploads/", "", rtrim($dir, DIRECTORY_SEPARATOR . "/") . "/"),
            "extension_list" => $extension_list,
            "dimension" => $dimension,
            "upload_type" => $upload_type ?? false,
            "dry_run" => $dry_run
        ]))->response;

        if(!$file['uploaded'])
            return $file;

        if($file['storage'] == FileUploadStorage::BUCKET)
            $file['url'] = ($bucket_url ?? "") . $file['url'];
        else
            $file['url'] = rtrim($dir, DIRECTORY_SEPARATOR . "/") . "/" . $file['url'];

        return $file;
    }

    /**
     * @return true[]
     *
     * @psalm-return array{apply_clean: true, add_to_entry: true}
     */
    private function __validate(string $field, mixed &$value, bool $is_required, array $options) : array
    {
        $field_name = str_replace(["_", "-"], " ", $options['field_name'] ?? $field);

        $add_to_entry = true;
        $apply_clean = true;

        $return = /**
         * @return true[]
         *
         * @psalm-return array{apply_clean: true, add_to_entry: true}
         */
            function() use (&$apply_clean, &$add_to_entry): array {
                return ["apply_clean" => $apply_clean, "add_to_entry" => $add_to_entry];
            };

        if(isset($options['is_file'])) {
            if(isset($options['bucket_url']) || isset(static::$_bucket_url))
                $options['upload_storage'] ??= FileUploadStorage::BUCKET;

            $max_size = $options['max_size'] ?? static::$_max_size ?? null;

            if(isset($options['max_size_in_mb']))
                $max_size = $options['max_size_in_mb'] * 1000000;

            $file = static::__file_upload_handler(
                post_name: $field,
                new_name: $options['new_file_name'] ?? $field,
                upload_sub_dir: $options['sub_dir'] ?? static::$_sub_dir ?? null,
                file_limit: $max_size,
                extension_list: $options['allowed_types'] ?? $options['allowed_extensions'] ?? static::$_allowed_types ?? null,
                dimension: $options['dimension'] ?? static::$_dimension ?? [800, 800],
                storage: $options['upload_storage'] ?? static::$_upload_storage ?? null,
                bucket_url: $options['bucket_url'] ?? static::$_bucket_url ?? null,
                dry_run: !empty(static::$_errors),
            );

            if(
                !$is_required && !$file['uploaded'] && (
                    $file['error_type'] == FileUploadErrors::FILE_NOT_SET || $file['error_type'] == FileUploadErrors::TMP_FILE_EMPTY
                )
            ) {
                $add_to_entry = false;
                return $return();
            }

            if(!$file['uploaded']) {
                $add_to_entry = $this->__add_error($field, $options['required_message'] ?? "$field_name: " . $file['error']);
                LayException::log($file['dev_error'] . "; Error Type: " . $file['error_type']->name, log_title: "VCM::Log");
                return $return();
            }

            $apply_clean = false;
            $value = $file['url'];
        }

        $is_empty = empty($value);

        if(isset($options['is_captcha'])) {
            $as_jwt = isset($options['captcha_jwt_field']);
            $jwt = $as_jwt ? $this->__get_field($options['captcha_jwt_field']) : null;

            $test = $this->__validate_captcha($value, $as_jwt, $jwt);

            if(!$test['valid']) {
                $add_to_entry = $this->__add_error($field, $options['required_message'] ?? "Field $field_name response: " . $test['message']);
                static::$_break_validation = true;
            }

            $add_to_entry = false;
            return $return();
        }

        if($is_required && $is_empty) {
            $add_to_entry = $this->__add_error($field, $options['required_message'] ?? "$field_name is required");
            return $return();
        }

        if($is_empty) return $return();

        if(isset($options['is_name'])) {
            $value = ucfirst(trim($value));
            preg_match("#^[a-zA-Z\-]+$#", $value, $test, PREG_UNMATCHED_AS_NULL);

            if(empty($test))
                $add_to_entry = $this->__add_error($field, $options['required_message'] ?? "Received an invalid text format for $field_name, please remove any special characters or multiple names");
        }

        if(isset($options['is_email']) && !filter_var($value, FILTER_VALIDATE_EMAIL))
            $add_to_entry = $this->__add_error($field, "Received an invalid email format for: $field_name");

        if(isset($options['is_bool'])) {
            if(!in_array(strtolower($value . ''), ['true', 'false', '1', '0']))
                $add_to_entry = $this->__add_error($field, "$field_name is not a valid boolean");
        }

        if(isset($options['is_num']) && !is_numeric($value))
            $add_to_entry = $this->__add_error($field, "$field_name is not a valid number");

        if(isset($options['is_uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1)
            $add_to_entry = $this->__add_error($field, "$field_name is not valid! A malformed value was encountered");

        if(isset($options['min_length']) && (strlen($value) < $options['min_length']))
            $add_to_entry = $this->__add_error($field, "$field_name must be at least {$options['min_length']} characters long");

        if(isset($options['max_length']) && (strlen($value) > $options['max_length']))
            $add_to_entry = $this->__add_error($field, "$field_name must not exceed {$options['max_length']} characters");

        if(isset($options['min_value']) && ($value < $options['min_value']))
            $add_to_entry = $this->__add_error($field, "$field_name must be greater than {$options['min_value']}");

        if(isset($options['max_value']) && ($value > $options['max_value']))
            $add_to_entry = $this->__add_error($field, "$field_name must be less than {$options['max_value']}");

        if(isset($options['match'])) {
            $to_match = $this->__get_field($options['match']['field']) ?? $options['match']['value'] ?? null;
            $match_field = $options['match']['field_name'] ?? $options['match']['field'] ?? null;

            if(isset($options['match']['value']))
                $message = $options['match']['message'] ?? "$field_name must equal \"{$options['match']['value']}\"";
            else
                $message = $options['match']['message'] ?? "$field_name must match $match_field";

            if($to_match == null || $to_match != $value)
                $add_to_entry = $this->__add_error($field, $message);
        }

        if(isset($options['must_contain']) && !in_array($value, $options['must_contain']))
            $add_to_entry = $this->__add_error($field, "$field_name must be one of: " . implode(', ', $options['must_contain']));

        if(isset($options['must_validate'])) {
            if(isset($options['must_validate']['fun'])) {
                if(!$options['must_validate']['fun']($value))
                    $add_to_entry = $this->__add_error($field, $options['must_validate']['message'] ?? "$field_name has not satisfied the criteria for submission");
            } else {
                $has_error = $options['must_validate']['fun_str']($value);

                if($has_error)
                    $add_to_entry = $this->__add_error($field, $has_error);
            }
        }

        if(isset($options['hash'])) {
            $apply_clean = false;
            $value = LayCrypt::hash($value);
        }

        return $return();
    }

    /**
     * Request entry that needs to be validated, cleaned and mapped
     *
     * @param array{
     *    request?: array|object,
     *    result_is_assoc?: bool,
     *    field: string,
     *    field_name?: string,
     *    required_message?: string,
     *    alias?: string,
     *    db_col?: string,
     *    before_validate?: callable(mixed) : string,
     *    before_clean?: callable(mixed) : string,
     *    after_clean?: callable(mixed) : string,
     *    must_contain?: array<int, string>,
     *    must_validate?: array{
     *      fun: callable(mixed) : bool,
     *      fun_str: callable(mixed) : string|null,
     *      message: string,
     *    },
     *    json_encode?: bool,
     *    required?: bool,
     *    is_email?: bool,
     *    is_name?: bool,
     *    is_num?: bool,
     *    is_bool?: bool,
     *    is_date?: bool,
     *    is_uuid?: bool,
     *    is_datetime?: bool,
     *    is_file?: bool,
     *    is_captcha?: bool,
     *    captcha_jwt_field?: string|null,
     *    hash?: bool,
     *    allowed_types?: array<int,FileUploadExtension>,
     *    allowed_extensions?: array<int,FileUploadExtension>,
     *    max_size?: int,
     *    max_size_in_mb?: float,
     *    new_file_name?: string,
     *    sub_dir?: string,
     *    dimension?: array,
     *    upload_storage?: FileUploadStorage,
     *    upload_type?: FileUploadType,
     *    bucket_url?: string,
     *    min_length?: int,
     *    max_length?: int,
     *    min_value?: double,
     *    max_value?: double,
     *    match?: array{
     *      field?: string,
     *      value?: mixed,
     *      message?: string,
     *    },
     *    clean?: bool|array{
     *      escape: EscapeType|array<int,EscapeType>,
     *      strict?: bool,
     *    },
     *    return_schema?: callable(mixed, string, array<string, mixed>) : mixed,
     * } $options
     */
    public function vcm(array $options ) : static
    {
        if(isset($options['request']) && empty(static::$_filled_request))
            static::vcm_start($options['request']);

        if(empty(static::$_filled_request) || static::$_break_validation)
            return $this;

        $is_required = $options['required'] ?? static::$_required ?? true;
        $field = str_replace("[]", "", $options['field']);
        $value = $this->__get_field($field);

        if(isset($options['before_validate']))
            $value = $options['before_validate']($value);

        if(is_array($value)) {
            foreach ($value as $val) {
                $x = $this->__validate(
                    $field, $val,
                    $is_required, $options
                );

                if(!$x['add_to_entry'])
                    return $this;
            }

            $value = isset($options['json_encode']) && !$options['json_encode'] ? $value : json_encode($value);

            $x['add_to_entry'] = true;
            $x['apply_clean'] = false;
        } else {
            $x = $this->__validate(
                $field, $value,
                $is_required, $options
            );
        }

        $add_to_entry = $x['add_to_entry'];
        $apply_clean = $x['apply_clean'];

        // Break on error or empty value
        if(!$add_to_entry || empty($value)) return $this;

        if(isset($options['is_date']) || isset($options['is_datetime'])) {
            $old_value = $value;
            $value = LayDate::date($old_value, format_index: isset($options['is_date']) ? 0 : -1);
            $apply_clean = false;

            if(!LayDate::is_valid($value)) {
                $field_name = str_replace(["_", "-"], " ", $options['field_name'] ?? $field);
                $this->__add_error($field, "$field_name with value [$old_value] is not a valid date");
                return $this;
            }
        }

        //TODO: Depreciate fun option
        if(isset($options['before_clean']) || isset($options['fun']))
            $value = ($options['before_clean'] ?? $options['fun'])($value);

        if($apply_clean) {
            // Clean and Map field
            $clean = $options['clean'] ?? static::$_clean_by_default ?? true;

            if ($clean) {
                $clean_type = is_array($clean) ? ($clean['escape'] ?? EscapeType::STRIP_TRIM_ESCAPE) : EscapeType::STRIP_TRIM_ESCAPE;
                $strict = $is_required ? ($clean['strict'] ?? false) : false;

                if (is_numeric($value) || is_bool($value))
                    $strict = false;

                $value = Escape::clean($value, $clean_type, [
                    'strict' => $strict
                ]);
            }
        }

        if(isset($options['after_clean']))
            $value = $options['after_clean']($value);

        $alias = $options['alias'] ?? $options['db_col'] ?? null;

        if(static::$_alias_required && !$alias)
            LayException::throw_exception(
                "An alias for field [$field] was not specified and is required by the validation rule. Please set one using the `alias` key",
                "VCM::Error"
            );

        //TODO: Depreciate return_struct
        $return_schema = $options['return_struct'] ?? $options['return_schema'] ?? static::$_return_schema ?? null;

        if($return_schema)
            $value = $return_schema($value, $alias ?? $field, $options);

        $result_is_assoc = $options['result_is_assoc'] ?? static::$_result_is_assoc;

        if($result_is_assoc)
            static::$_entries[$alias ?? $field] = $value;
        else
            static::$_entries[] = $value;

        return $this;
    }

    /**
     * Set a general rule that applies to every object of a particular request
     *
     * @param VcmRules $options
     */
    public function vcm_rules(array $options) : static
    {
        static::$_required = $options['required'] ?? null;
        static::$_clean_by_default = $options['clean'] ?? null;
        static::$_alias_required = $options['alias_required'] ?? null;
        static::$_sub_dir = $options['sub_dir'] ?? null;
        static::$_allowed_types = $options['allowed_types'] ?? $options['allowed_extensions'] ?? null;

        static::$_max_size = $options['max_size'] ?? null;

        if(isset($options['max_size_in_mb']))
            static::$_max_size = $options['max_size_in_mb'] * 1000000;

        static::$_new_file_name = $options['new_file_name'] ?? null;
        static::$_dimension = $options['dimension'] ?? null;
        static::$_upload_storage = $options['upload_storage'] ?? null;
        static::$_bucket_url = $options['bucket_url'] ?? null;
        static::$_upload_handler = $options['upload_handler'] ?? null;

        static::$_return_schema = $options['return_struct'] ?? $options['return_schema'] ?? null;
        static::$_result_is_assoc = $options['result_is_assoc'] ?? true;

        return $this;
    }

    /**
     * Initialize the request from the server for validation
     * @param array|object $request Post Request
     * @param null|VcmRules $vcm_rules vcm rules can also be set via this parameter
     * @return static
     */
    public static function vcm_start(array|object $request, ?array $vcm_rules = null) : static
    {
        static::$_filled_request = $request;

        static::$_entries = [];
        static::$_errors = null;
        static::$_break_validation = false;

        static::$_required = null;
        static::$_alias_required = null;
        static::$_clean_by_default = null;
        static::$_sub_dir = null;
        static::$_allowed_types = null;
        static::$_max_size = null;
        static::$_new_file_name = null;
        static::$_dimension = null;
        static::$_upload_storage = null;
        static::$_bucket_url = null;
        static::$_upload_handler = null;
        static::$_return_schema = null;
        static::$_result_is_assoc = true;

        static::$VCM_INSTANCE ??= new static();

        if(!empty($vcm_rules))
            static::$VCM_INSTANCE->vcm_rules($vcm_rules);

        return static::$VCM_INSTANCE;
    }

    /**
     * Returns all the validated entries as an array.
     * It returns the data matching the result with the database column names.
     *
     * @return array
     */
    public static function vcm_end() : array
    {
        return static::$_entries;
    }

    /**
     * An alias for `vcm_end()`
     * @return array
     * @see vcm_end()
     */
    public static function vcm_data() : array
    {
        return static::vcm_end();
    }

    /**
     * Return all the errors received by the validator
     *
     * @param bool $as_string
     *
     * @return array|null|string
     */
    public static function vcm_errors(bool $as_string = true) : array|string|null
    {
        $errors = static::$_errors ?? null;

        if(empty(static::$_entries) and !$errors)
            $errors = ["Form submission is invalid, please check if you submitted a file above the specified file limit"];

        if($as_string && $errors)
            return implode("<br>\n", $errors);

        return $errors;
    }


}