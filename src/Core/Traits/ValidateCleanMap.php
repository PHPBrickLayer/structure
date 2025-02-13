<?php

namespace BrickLayer\Lay\Core\Traits;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadErrors;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadExtension;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadStorage;
use BrickLayer\Lay\Libs\FileUpload\Enums\FileUploadType;
use BrickLayer\Lay\Libs\FileUpload\FileUpload;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use Closure;
use Exception;

trait ValidateCleanMap {
    private static array|object|null $_filled_request;
    private static array $_entries = [];
    private static array $_errors = [];

    private static ?bool $_required;
    private static ?bool $_db_col_required;
    private static array|bool|null $_clean_by_default;

    private static ?string $_sub_dir;
    private static ?array $_allowed_types;
    private static ?int $_max_size;
    private static ?string $_new_file_name;
    private static ?array $_dimension;
    private static ?FileUploadStorage $_upload_storage;
    private static ?string $_bucket_url;
    private static ?closure $_upload_handler;

    private function __add_error(string $field, string $message): void
    {
        self::$_errors[$field] = $message;
    }

    private function __get_field(string $key) : mixed
    {
        if(is_array(self::$_filled_request))
            return self::$_filled_request[$key] ?? null;

        return self::$_filled_request->{$key} ?? null;
    }

    private function __validate_captcha(string $value, string $captcha_key = "CAPTCHA_CODE") : bool
    {
        if (!isset($_SESSION[$captcha_key]))
            return false;

        if ($value == $_SESSION[$captcha_key])
            return true;

        return false;
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
     * @param FileUploadStorage $storage
     * @param string|null $bucket_url
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
        ?string $bucket_url = null
    ) : array
    {
        // If dev wishes to use a custom upload handler, it must follow the params list chronologically,
        // and return an array.
        if(isset(self::$_upload_handler)) {
            return self::$_upload_handler->call(
                $this,
                $post_name, $new_name, $upload_sub_dir, $file_limit,
                $extension_list, $dimension, $storage, $bucket_url
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
     * Request entry that needs to be validated, clean and mapped
     *
     * @param array{
     *    field: string,
     *    field_name?: string,
     *    db_col: string,
     *    must_contain?: array,
     *    must_validate?: array{
     *     fun: callable,
     *     message: string,
     *    },
     *    required?: bool,
     *    is_email?: bool,
     *    is_num?: bool,
     *    is_date?: bool,
     *    is_file?: bool,
     *    is_captcha?: bool,
     *    captcha_key?: string,
     *    allowed_types?: FileUploadExtension,
     *    max_size?: int,
     *    new_file_name?: string,
     *    sub_dir?: string,
     *    dimension?: array,
     *    upload_storage?: FileUploadStorage,
     *    bucket_url?: string,
     *    min_length?: int,
     *    max_length?: int,
     *    match?: array{
     *      field: string,
     *      value: mixed
     *    },
     *    clean?: bool|array{
     *      escape: EscapeType,
     *      strict: bool,
     *    },
     * } $options
     *
     * @return ValidateCleanMap
     */
    public function vcm(array $options ) : self
    {
        if(empty(self::$_filled_request))
            return $this;

        $has_error = false;
        $is_required = $options['required'] ?? self::$_required ?? false;
        $apply_clean = true;

        $field = $options['field'];
        $field_name = str_replace(["_", "-"], " ", $options['field_name'] ?? $field);
        $value = $this->__get_field($field);
        $is_empty = empty($value);

        if(isset($options['is_file'])) {
            $file = self::__file_upload_handler(
                post_name: $field,
                new_name: $options['new_file_name'],
                upload_sub_dir: $options['sub_dir'] ?? self::$_sub_dir ?? null,
                file_limit: $options['max_size'] ?? self::$_max_size ?? null,
                extension_list: $options['allowed_types'] ?? self::$_allowed_types ?? null,
                dimension: $options['dimension'] ?? self::$_dimension ?? [800, 800],
                storage: $options['upload_storage'] ?? self::$_upload_storage ?? null,
                bucket_url: $options['bucket_url'] ?? self::$_bucket_url ?? null,
            );

            if(!$file['uploaded']) {
                $this->__add_error($field, "$field_name: " . $file['error']);
                LayException::log($file['dev_error'], log_title: "VCM::Log");
                return $this;
            }

            $is_empty = false;
            $apply_clean = false;
            $value = $file['url'];
        }

        if(isset($options['is_captcha'])) {
            $test = $this->__validate_captcha($value, $options['captcha_key']);

            if(!$test)
                $this->__add_error($field, "The value of captcha is incorrect, please check the field: $field_name and try again");

            return $this;
        }

        if($is_required && $is_empty) {
            $this->__add_error($field, "$field_name is required");
            return $this;
        }

        if($is_empty) return $this;

        if(isset($options['is_email']) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->__add_error($field, "Received an invalid email format for: $field_name");
            $has_error = true;
        }

        if(isset($options['is_num']) && !is_numeric($value)) {
            $this->__add_error($field, "$field_name is not a valid number");
            $has_error = true;
        }

        if(isset($options['min_length']) && (strlen($value) < $options['min_length'])) {
            $this->__add_error($field, "$field_name must be at least {$options['min_length']} characters long");
            $has_error = true;
        }

        if(isset($options['max_length']) && (strlen($value) > $options['max_length'])) {
            $this->__add_error($field, "$field_name must not exceed {$options['max_length']} characters");
            $has_error = true;
        }

        if(isset($options['match']) && ($options['match']['value'] != $value)) {
            $this->__add_error($field, "$field_name must match {$options['match']['field']}");
            $has_error = true;
        }

        if(isset($options['must_contain']) && !in_array($value, $options['must_contain'])) {
            $this->__add_error($field, "$field_name must be one of: " . implode(', ', $options['must_contain']));
            $has_error = true;
        }

        if(isset($options['must_validate']) && !$options['must_validate']['fun']($value)) {
            $this->__add_error($field, $options['must_validate']['message'] ?? "$field_name has not satisfied the criteria for submission");
            $has_error = true;
        }

        // Break on error
        if($has_error) return $this;


        if(isset($options['is_date'])) {
            $value = LayDate::date($value, format_index: 0);
            $apply_clean = false;
        }

        if($apply_clean) {
            // Clean and Map field
            $clean = $options['clean'] ?? self::$_clean_by_default ?? null;

            if ($clean) {
                $clean_type = is_array($clean) ? ($clean['escape'] ?? EscapeType::STRIP_TRIM_ESCAPE) : EscapeType::STRIP_TRIM_ESCAPE;
                $strict = $is_required ? ($clean['strict'] ?? false) : false;

                if (is_numeric($value))
                    $strict = false;

                $value = Escape::clean($value, $clean_type, [
                    'strict' => $strict
                ]);
            }
        }

        if(self::$_db_col_required && !isset($options['db_col']))
            LayException::throw_exception(
                "DB column for field [$field] was not specified and is required by the validation rule",
                "VCM::Error"
            );

        if(isset($options['db_col']))
            self::$_entries[$options['db_col']] = $value;
        else
            self::$_entries[$field] = $value;

        return $this;
    }

    /**
     * Set a general rule that applies to every object of a particular request
     *
     * @param array{
     *     required?: bool,
     *     db_col_required?: bool,
     *     clean?: bool|array{
     *       escape: EscapeType,
     *       strict: bool,
     *     },
     *     sub_dir?: string,
     *     allowed_types?: FileUploadExtension,
     *     max_size?: int,
     *     new_file_name?: string,
     *     dimension?: array,
     *     upload_storage?: FileUploadStorage,
     *     bucket_url?: string,
     *     upload_handler?: callable,
     *  } $options
     *
     * @return ValidateCleanMap
     */
    public function vcm_rules(array $options) : self
    {
        self::$_required = $options['required'] ?? null;
        self::$_clean_by_default = $options['clean'] ?? null;
        self::$_db_col_required = $options['db_col_required'] ?? null;
        self::$_sub_dir = $options['sub_dir'] ?? null;
        self::$_allowed_types = $options['allowed_types'] ?? null;
        self::$_max_size = $options['max_size'] ?? null;
        self::$_new_file_name = $options['new_file_name'] ?? null;
        self::$_dimension = $options['dimension'] ?? null;
        self::$_upload_storage = $options['upload_storage'] ?? null;
        self::$_bucket_url = $options['bucket_url'] ?? null;
        self::$_upload_handler = $options['upload_handler'] ?? null;

        return $this;
    }

    /**
     * Initialize the request from the server for validation
     * @param array|object $request Post Request
     * @return self
     */
    public static function vcm_start(array|object $request) : self
    {
        self::$_filled_request = $request;

        self::$_entries = [];
        self::$_errors = [];

        self::$_required = null;
        self::$_db_col_required = null;
        self::$_clean_by_default = null;
        self::$_sub_dir = null;
        self::$_allowed_types = null;
        self::$_max_size = null;
        self::$_new_file_name = null;
        self::$_dimension = null;
        self::$_upload_storage = null;
        self::$_bucket_url = null;
        self::$_upload_handler = null;

        return new self();
    }

    /**
     * Get all the validated entries for further usage
     * @return array
     */
    public static function vcm_end() : array
    {
        return self::$_entries;
    }

    /**
     * Return all the errors received by the validator
     * @param bool $as_string
     * @return array|string|null
     */
    public static function vcm_errors(bool $as_string = false) : array|null|string
    {
        $errors = self::$_errors ?? null;

        if(empty(self::$_entries))
            $errors = ["Form submission is invalid, please check if you submitted a file above the specified file limit"];

        if($as_string && $errors)
            return implode("<br>\n", $errors);

        return $errors;
    }


}