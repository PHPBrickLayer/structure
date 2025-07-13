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
 * @phpstan-import-type FileUploadReturn from FileUpload
 *
 * @phpstan-type VcmRules array{
 *    required?: bool,
 *    json_encode?: bool,
 *    result_is_assoc?: bool,
 *    alias_required?: bool,
 *    clean?: bool|array{
 *      escape: EscapeType|array<int,EscapeType>,
 *      strict: bool,
 *    },
 *    sub_dir?: string,
 *    allowed_types?: array<int, FileUploadExtension>,
 *    allowed_extensions?: array<int, FileUploadExtension>,
 *    max_size?: int,
 *    max_size_in_mb?: float,
 *    new_file_name?: string,
 *    dimension?: array<int>,
 *    upload_storage?: FileUploadStorage,
 *    bucket_url?: string,
 *    each?: callable(mixed $value, int $index) : mixed,
 *    upload_handler?: callable,
 *    return_schema?: callable(mixed $value, string $alias, array<string, mixed> $options) : mixed,
 *    return_struct?: callable(mixed $value, string $alias, array<string, mixed> $options) : mixed,
 *
 *    // Instructs VCM to return result where each entry has its child in an array (VCM way)
 *    // Or make a 2D array and return each result as an individual role of themselves, just like a result from a database
 *    group_result?: bool,
 *  }
 *
 * @phpstan-type VcmOptions array{
 *     // This is the $_POST request
 *     request?: array<string|int, mixed>|object,
 *
 *     // A custom function to handle validation rather than using the inbuilt validator
 *     validator: callable(string $field, mixed &$value, bool $is_required, VcmOptions $options): array{ apply_clean: bool, add_to_entry: bool },
 *
 *     // By default, VCM returns an assoc array with the key being (`alias` ?? `db_col` ?? `field`),
 *     // and the value is the validated result of the current field. With this option, vcm can append the value to the
 *     // array, then it won't be an assoc array again. default: true [assoc]
 *     result_is_assoc?: bool,
 *
 *     // This is the name of the $_POST value currently being validated
 *     field?: string, // Either of them
 *     name?: string, // should be used
 *
 *     // Field name is a human-friendly name for the current field being validated.
 *     // Example: instead of "first_name is required"; specify a `field_name` of "First Name"
 *     // then the error message becomes "First Name is required"
 *     field_name?: string,
 *
 *     // `alias` and `db_col` are the same thing. They are used to create an alias for the current field, so that when
 *     // the `vcm_data()` or `vcm_end()` method is called, this alias replaces the `field`.
 *     // If not specified, the `field` is used in the returned dataset
 *     alias?: string,
 *     db_col?: string,
 *
 *     default_value?: string, // This is a value VCM should assign to a non-required field if it's empty
 *
 *     // This modifies the default error message when a required field is not filled
 *     required_message?: string,
 *
 *     each?: callable(mixed, int) : mixed, // A callback to run on each iteration of an array field
 *     before_validate?: callable(mixed) : string,
 *     before_clean?: callable(mixed) : string,
 *     after_clean?: callable(mixed) : string,
 *
 *     // This validates if the value of the current field is anything in the array
 *     must_contain?: array<int, string>,
 *
 *     // Use callbacks to ensure the current field matches the criteria specified in the callback function.
 *     // If you pass a callback instead of an array, it will be same behaviour as using the `fun` key, and a default
 *     // message will be used: Invalid `field_name`!
 *     must_validate?: array{
 *       // You can either use this or `fun_str`. If the callback return true, then vcm assumes the criteria was met,
 *       // else the criteria was not met, and the value of message is returned as the error message
 *       fun: callable(mixed) : bool,
 *       message: string,
 *
 *       // When using this one, if it returns a string, vcm assumes the criteria was not met, and uses the string as
 *       // the error message. But if it returns a null, then the criteria specified was met
 *       fun_str: callable(mixed) : string|null,
 *     }|callable(mixed) : bool,
 *
 *     // instructs vcm to json_encode any the current field if it's an array type. [default: true]
 *     json_encode?: bool,
 *
 *     required?: bool,
 *     is_email?: bool,
 *     is_name?: bool,
 *     is_num?: bool,
 *     is_bool?: bool,
 *     is_date?: bool,
 *     is_datetime?: bool,
 *     is_uuid?: bool,
 *
 *     // Instructs vcm to has the current field using php's password_hash method. This is particularly useful for passwords
 *     hash?: bool,
 *
 *     //<<START CAPTCHA
 *     // Let's vcm know that the current field is a captcha field, so it uses LayCaptcha class to validate it.
 *     // If your form contains captcha, then the captcha should be the first vcm field you define,
 *     // because if captcha does not pass validation, then the whole vcm process is aborted
 *     is_captcha?: bool,
 *     // If you are using captcha as a jwt, then you need to submit the jwt with the form, and this option accepts the
 *     // name you assigned to the captcha jwt value while submitting the form.
 *     captcha_jwt_field?: string|null,
 *     //<<END CAPTCHA
 *
 *     //<<START FILE UPLOAD
 *     // This one can come in handy when your field can either be a file or a link to a file.
 *     // Vcm will process the file if it detects the current field is a file, else it will process it like a normal text.
 *     maybe_file?: bool,
 *
 *     // @see FileUpload
 *     pre_upload?: callable(?string $tmp_file, ?array $file):(array|true),
 *     post_upload?: callable(FileUploadReturn $response):void,
 *     is_file?: bool,
 *     allowed_types?: array<int,FileUploadExtension>,
 *     allowed_extensions?: array<int,FileUploadExtension>,
 *     max_size?: int,
 *     max_size_in_mb?: float,
 *     new_file_name?: string,
 *     sub_dir?: string,
 *     dimension?: array<int>,
 *     upload_storage?: FileUploadStorage,
 *     upload_type?: FileUploadType,
 *     bucket_url?: string,
 *     file_size_field?: string,
 *     file_type_field?: string,
 *     file_ratio_field?: string,
 *     //<<END FILE UPLOAD
 *
 *     min_length?: int, // Minimum strings the current field should contain
 *     max_length?: int, // Maximum strings the current field should contain
 *     min_value?: double, // Minimum figure the current field can be
 *     max_value?: double, // Maximum figure the current field can be
 *
 *     // Instruct vcm that the current field value must match the value of an already validated field.
 *     // `message` is the error message that will display if they don't match.
 *     // This option can be used for password confirmation where you want `password` to match `retype_password`
 *     match?: array{
 *       field?: string,
 *       value?: mixed,
 *       message?: string,
 *     },
 *
 *     clean?: bool|array{
 *       escape: EscapeType|array<int,EscapeType>,
 *       strict?: bool,
 *     },
 *
 *     // If you have a specific array structure you want each validated vcm to return as, then use any of them
 *     // callable($validated_value, $alias ?? $field, $options)
 *     return_schema?: callable(mixed, string, array<string, mixed>) : mixed,
 *     return_struct?: callable(mixed, string, array<string, mixed>) : mixed,
 *  }
 */
trait ValidateCleanMap {
    protected static self $VCM_INSTANCE;

    private static array|object|null $_filled_request;
    private static array $_entries = [];
    private static ?array $_errors;
    private static ?bool $_break_validation = false;
    private static ?bool $_group_result = false;

    private static ?bool $_json_encode;
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
    private static ?closure $_each;
    private static ?closure $_upload_handler;
    private static ?closure $_return_schema;
    private static bool $_result_is_assoc = true;

    private function __get_field(string $key) : mixed
    {
        if(is_array(static::$_filled_request))
            return static::$_filled_request[$key] ?? null;

        return static::$_filled_request->{$key} ?? null;
    }

    /**
     *
     * @return array{valid: bool, message: string}
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
     * @return FileUploadReturn
     * @throws Exception
     */
    private function __file_upload_handler(
        string $post_name,
        string $new_name,
        ?string $upload_sub_dir,
        ?int $file_limit,
        ?array $extension_list,
        ?array $dimension,
        ?FileUploadStorage $storage = FileUploadStorage::BUCKET,
        ?string $bucket_url = null,
        ?FileUploadType $upload_type = null,
        bool $dry_run = false,
        ?callable $pre_upload = null,
        ?callable $post_upload = null,
    ) : array
    {
        // If dev wishes to use a custom upload handler, it must follow the params list chronologically,
        // and return an array.
        if(isset(static::$_upload_handler)) {
            return static::$_upload_handler->call(
                $this,
                $post_name, $new_name, $upload_sub_dir, $file_limit,
                $extension_list, $dimension, $storage, $bucket_url,
                $upload_type, $dry_run, $pre_upload
            );
        }

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
            "dry_run" => $dry_run,
            "pre_upload" => $pre_upload
        ]))->response;

        if(!$file['uploaded'])
            return $file;

        if($file['storage'] == FileUploadStorage::BUCKET)
            $file['url'] = ($bucket_url ?? "") . $file['url'];
        else
            $file['url'] = rtrim($dir, DIRECTORY_SEPARATOR . "/") . "/" . $file['url'];

        if($post_upload)
            return $post_upload($file);

        return $file;
    }

    /**
     * @param string $field
     * @param mixed $value
     * @param bool $is_required
     * @param VcmOptions $options
     *
     * @return array{apply_clean: bool, add_to_entry: bool}
     */
    private function __validate(string $field, mixed &$value, bool $is_required, array $options) : array
    {
        $field_name = $options['field_name'];

        $add_to_entry = true;
        $apply_clean = true;

        // The reason this is like this and not a traditional arrow function, is because as of the time of writing,
        // PHP doesn't get the updated value of $apply_clean and the other when we call $return at a later stage.
        // But with a traditional function, it gets the latest value
        $return = function () use (&$apply_clean, &$add_to_entry) {
            return ["apply_clean" => $apply_clean, "add_to_entry" => $add_to_entry];
        };

        if(isset($options['is_file']) || isset($options['maybe_file'], $_FILES[$field])) {
            $storage_type = $options['bucket_url'] ?? static::$_bucket_url ?? null;

            if($storage_type)
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
                $add_to_entry = $this->report_error($field, $options['required_message'] ?? "$field_name: " . $file['error']);
                LayException::log($file['dev_error'] . "; Error Type: " . $file['error_type']->name, log_title: "VCM::Log");
                return $return();
            }

            $apply_clean = false;
            $value = $file['url'];

            // Add the file storage to the data entry if specified by the dev
            if(isset($options['file_size_field']))
                $this->add_to_entry($options['file_size_field'], $file['size'], $options);

            // Add the file type
            if(isset($options['file_type_field']))
                $this->add_to_entry($options['file_type_field'], $file['file_type']->name, $options);

            // Add the file type
            if(isset($options['file_ratio_field']) && $file['upload_type'] == FileUploadType::IMG)
                $this->add_to_entry($options['file_ratio_field'], ["width" => $file['width'], "height" => $file['height']], $options);
        }

//        $is_empty = empty($value);
        $is_empty = $value === null;

        if(isset($options['is_captcha'])) {
            $as_jwt = isset($options['captcha_jwt_field']);
            $jwt = $as_jwt ? $this->__get_field($options['captcha_jwt_field']) : null;

            $test = $this->__validate_captcha($value, $as_jwt, $jwt);

            if(!$test['valid']) {
                $add_to_entry = $this->report_error($field, $options['required_message'] ?? "Field $field_name response: " . $test['message']);
                static::$_break_validation = true;
            }

            $add_to_entry = false;
            return $return();
        }

        if($is_required && $is_empty) {
            $add_to_entry = $this->report_error($field, $options['required_message'] ?? "$field_name is required");
            return $return();
        }

        if($is_empty) return $return();

        if(isset($options['is_name'])) {
            $value = ucfirst(trim($value));
            preg_match("#^[a-zA-Z\-]+$#", $value, $test, PREG_UNMATCHED_AS_NULL);

            if(empty($test))
                $add_to_entry = $this->report_error($field, $options['required_message'] ?? "Received an invalid text format for $field_name, please remove any special characters or multiple names");
        }

        if(isset($options['is_email']) && !filter_var($value, FILTER_VALIDATE_EMAIL))
            $add_to_entry = $this->report_error($field, "Received an invalid email format for: $field_name");

        if(isset($options['is_bool'])) {
            if(in_array(strtolower($value . ''), ['true', 'false', '1', '0', true, false])) {
                $value = filter_var($value, FILTER_VALIDATE_BOOL);
                $apply_clean = false;
            }
            else
                $add_to_entry = $this->report_error($field, "$field_name is not a valid boolean");
        }

        if(isset($options['is_num']) && !is_numeric($value))
            $add_to_entry = $this->report_error($field, "$field_name is not a valid number");

        if(isset($options['is_uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1)
            $add_to_entry = $this->report_error($field, "$field_name is not valid! A malformed value was encountered");

        if(isset($options['min_length']) && (strlen($value) < $options['min_length']))
            $add_to_entry = $this->report_error($field, "$field_name must be at least {$options['min_length']} characters long");

        if(isset($options['max_length']) && (strlen($value) > $options['max_length']))
            $add_to_entry = $this->report_error($field, "$field_name must not exceed {$options['max_length']} characters");

        if(isset($options['min_value']) && ($value < $options['min_value']))
            $add_to_entry = $this->report_error($field, "$field_name must be greater than {$options['min_value']}");

        if(isset($options['max_value']) && ($value > $options['max_value']))
            $add_to_entry = $this->report_error($field, "$field_name must be less than {$options['max_value']}");

        if(isset($options['match'])) {
            $to_match = $this->__get_field($options['match']['field']) ?? $options['match']['value'] ?? null;
            $match_field = $options['match']['field_name'] ?? $options['match']['field'] ?? null;

            if(isset($options['match']['value']))
                $message = $options['match']['message'] ?? "$field_name must equal \"{$options['match']['value']}\"";
            else
                $message = $options['match']['message'] ?? "$field_name must match $match_field";

            if($to_match == null || $to_match != $value)
                $add_to_entry = $this->report_error($field, $message);
        }

        if(isset($options['must_contain']) && !in_array($value, $options['must_contain']))
            $add_to_entry = $this->report_error($field, "$field_name must be one of: " . implode(', ', $options['must_contain']));

        if(isset($options['must_validate'])) {
            if(is_callable($options['must_validate'])) {
                if (!$options['must_validate']($value, $options))
                    $add_to_entry = $this->report_error($field, "$field_name has not satisfied the criteria for submission");
            }
            else {
                if (isset($options['must_validate']['fun']) && !$options['must_validate']['fun']($value, $options)) {
                    $add_to_entry = $this->report_error(
                        $field,
                        $options['must_validate']['message'] ??
                        "$field_name has not satisfied the criteria for submission"
                    );
                }

                if (isset($options['must_validate']['fun_str']) && $has_error = $options['must_validate']['fun_str']($value, $options)) {
                    $add_to_entry = $this->report_error($field, $has_error);
                }
            }
        }

        if(isset($options['hash'])) {
            $apply_clean = false;
            $value = LayCrypt::hash($value);
        }

        if(isset($options['is_date']) || isset($options['is_datetime'])) {
            $old_value = $value;
            $value = LayDate::date($old_value, format_index: isset($options['is_date']) ? 0 : -1);
            $apply_clean = false;

            if(!LayDate::is_valid($value))
                $add_to_entry = $this->report_error($field, "$field_name with value [$old_value] is not a valid date");
        }

        return $return();
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param VcmOptions $options
     * @return void
     */
    private function add_to_entry(string $key, mixed $value, array $options) : void
    {
        $result_is_assoc = $options['result_is_assoc'] ?? static::$_result_is_assoc;

        if($result_is_assoc) {
            if(static::$_group_result) {
                static::$_entries[$options['array_index']][$key] = $value;
                return;
            }

            static::$_entries[$key] = $value;
            return;
        }

        if(static::$_group_result) {
            static::$_entries[$options['array_index']][] = $value;
            return;
        }

        static::$_entries[] = $value;
    }

    /**
     * Reports Error to the VCM, which can later be gathered from the VCM array object
     * @param string $field
     * @param string $message
     * @return false
     */
    public final function report_error(string $field, string $message): false
    {
        static::$_errors[$field] = $message;
        return false;
    }

    /**
     * Request entry that needs to be validated, cleaned and mapped
     *
     * @param VcmOptions $options
     */
    public function vcm(array $options) : static
    {
        if(isset($options['request']) && empty(static::$_filled_request))
            static::vcm_start($options['request']);

        if(empty(static::$_filled_request) || static::$_break_validation)
            return $this;

        $is_required = $options['required'] ?? static::$_required ?? true;
        $field = str_replace("[]", "", $options['field'] ?? $options['name']);
        $value = $this->__get_field($field);
        $default_value = $options['default_value'] ?? null;
        $validator = $options['validator'] ?? [$this, "__validate"];
        $group_result = static::$_group_result ?? false;
        $options['array_index'] = 0;

        $options['field_name'] = str_replace(["_", "-"], " ", $options['field_name'] ?? $field);

        if(!$is_required && empty($value) && $default_value)
            $value = $default_value;

        if(isset($options['before_validate']))
            $value = $options['before_validate']($value, $options);


        if (is_array($value)) {
            foreach ($value as $index => $val) {
                $options['array_index'] = $index;

                $x = $validator(
                    $field, $val,
                    $is_required, $options
                );

                if (!$x['add_to_entry'])
                    return $this;

                $each = $options['each'] ?? self::$_each ?? null;

                if ($each)
                    $value[$index] = $each($val, $index);

                // Since we are grouping result, VCM assumes the request set is coming as a perfect array, meaning
                // name[], name[];  or design[][], design[][]
                // age[], age[];    or time[][], time[][]
                if($group_result) {
                    if(isset($options['before_clean']))
                        $value[$index] = $options['before_clean']($value[$index], $options);

                    if(isset($options['after_clean']))
                        $value[$index] = $options['after_clean']($value[$index], $options);

                    $alias = $options['alias'] ?? $options['db_col'] ?? null;

                    if(static::$_alias_required && !$alias)
                        LayException::throw_exception(
                            "An alias for field [$field] was not specified and is required by the validation rule. Please set one using the `alias` key",
                            "VCM::Error"
                        );

                    $return_schema = $options['return_struct'] ?? $options['return_schema'] ?? static::$_return_schema ?? null;

                    if($return_schema)
                        $value[$index] = $return_schema($value[$index], $alias ?? $field, $options);

                    $this->add_to_entry($field, $value[$index] , $options);
                }
            }

            if($group_result) return $this;

            $encode = $options['json_encode'] ?? static::$_json_encode ?? true;
            $value = $encode ? json_encode($value) : $value;

            $x['add_to_entry'] = true;
            $x['apply_clean'] = false;
        } else {
            $x = $validator(
                $field, $value,
                $is_required, $options
            );
        }

        $add_to_entry = $x['add_to_entry'];
        $apply_clean = $x['apply_clean'];

        // Break on error or empty value
        if(!$add_to_entry || empty($value)) return $this;

        if(isset($options['before_clean']))
            $value = $options['before_clean']($value, $options);

        if($apply_clean) {
            // Clean and Map field
            $clean = $options['clean'] ?? static::$_clean_by_default ?? true;

            if ($clean) {
                $clean_type = is_array($clean) ? ($clean['escape'] ?? EscapeType::STRIP_TRIM) : EscapeType::STRIP_TRIM;
                $strict = $is_required ? ($clean['strict'] ?? false) : false;

                if (is_numeric($value) || is_bool($value))
                    $strict = false;

                $value = Escape::clean($value, $clean_type, [
                    'strict' => $strict
                ]);
            }
        }

        if(isset($options['after_clean']))
            $value = $options['after_clean']($value, $options);

        $alias = $options['alias'] ?? $options['db_col'] ?? null;

        if(static::$_alias_required && !$alias)
            LayException::throw_exception(
                "An alias for field [$field] was not specified and is required by the validation rule. Please set one using the `alias` key",
                "VCM::Error"
            );

        $return_schema = $options['return_struct'] ?? $options['return_schema'] ?? static::$_return_schema ?? null;

        if($return_schema)
            $value = $return_schema($value, $alias ?? $field, $options);

        $this->add_to_entry($alias ?? $field, $value, $options);

        return $this;
    }

    /**
     * Used to process a request where the post data is a non-assoc array.
     * @param \Closure():(null|array) $vcm_rules All the vcm rules to execute in each iteration of the headless request
     */
    public function headless(callable $vcm_rules) : void
    {
        $x = [];

        foreach (self::request(as_array: true) as $i => $request) {
            static::$_filled_request = $request;
            $entry = $vcm_rules();
            $x[] = $entry ?? static::$_entries;
        }

        static::$_entries = $x;
    }

    /**
     * Set a general rule that applies to every object of a particular request
     *
     * @param VcmRules $options
     */
    public function vcm_rules(array $options) : static
    {
        static::$_required = $options['required'] ?? null;
        static::$_json_encode = $options['json_encode'] ?? null;
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

        static::$_each = $options['each'] ?? null;
        static::$_return_schema = $options['return_struct'] ?? $options['return_schema'] ?? null;
        static::$_result_is_assoc = $options['result_is_assoc'] ?? true;
        static::$_group_result = $options['group_result'] ?? null;

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
        static::$_break_validation = false;
        static::$_result_is_assoc = true;

        list(
            static::$_errors,
            static::$_required,
            static::$_json_encode,
            static::$_alias_required,
            static::$_clean_by_default,
            static::$_sub_dir,
            static::$_allowed_types,
            static::$_max_size,
            static::$_new_file_name,
            static::$_dimension,
            static::$_upload_storage,
            static::$_bucket_url,
            static::$_upload_handler,
            static::$_return_schema,
            static::$_group_result,
            static::$_each,
            ) = null;

        static::$VCM_INSTANCE ??= new static();

        if(!empty($vcm_rules))
            static::$VCM_INSTANCE->vcm_rules($vcm_rules);

        return static::$VCM_INSTANCE;
    }

    /**
     * Add a new entry dynamically. You cannot update the entries with this method,
     * use `vcm_update_entry` for that
     *
     */
    public static function vcm_new_entry(string $key, mixed $value) : void
    {
        if(isset(self::$_entries[$key]))
            LayException::throw_exception(
                "Trying to update an existing property to your vcm entry. You can only do that in the `vcm_update_entry` function"
            );

        self::$_entries[$key] = $value;
    }

    public static function vcm_update_entry(string $key, mixed $value) : void
    {
        $append = str_contains($key, "[]");

        if($append)
            $key = str_replace("[]", "", $key);

        if(!isset(self::$_entries[$key]))
            LayException::throw_exception(
                "Trying to dynamically add a new property to your VCM entries. You can only do that using the `vcm_new_entry`"
            );

        if($append)
            self::$_entries[$key][] = $value;
        else
            self::$_entries[$key] = $value;
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

        if(empty(static::vcm_data()) and !$errors)
            $errors = ["Form submission is invalid, please check if you submitted a file above the specified file limit"];

        if($as_string && $errors)
            return implode("<br>\n", $errors);

        return $errors;
    }


}