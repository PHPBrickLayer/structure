<?php

namespace BrickLayer\Lay\Libs\Cron;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;

final class LayCron
{
    private const TMP_CRONTAB_TXT = "/tmp/crontab.txt";
    private const CRON_JOBS_JSON = "cron_jobs.json";
    private const APP_ID_KEY = "--LAY_APP_ID";
    private const DB_SCHEMA = [
        "mailto" => "",
        "jobs" => [],
    ];

    private static string $PHP_BIN;
    private static string $time_zone  = "Africa/Lagos";
    private string $output_file;
    private bool $just_once_set = false;

    private array $exec_output = [
        "exec" => true,
        "msg" => "Job exists, using old schedule!"
    ];

    private array $jobs_list;
    private string $report_email;
    private string $job_id;
    private string $minute = "*"; // (0 - 59)
    private string $hour = "*"; // (0 - 23)
    private string $day_of_the_month = "*"; // (1 - 31)
    private string $month = "*"; // (1 - 12)
    private string $day_of_the_week = "*"; // (0 - 6) (Sunday=0 or 7)

    public static function php_bin(): string
    {
        if(isset(self::$PHP_BIN))
            return self::$PHP_BIN;

        if(PHP_BINARY)
            return self::$PHP_BIN = PHP_BINARY;

        if($bin = shell_exec("which php"))
            return self::$PHP_BIN = trim($bin);

        return self::$PHP_BIN = "/usr/bin/php";
    }

    /**
     * @param bool $suppress_win_exception
     *
     * @return false|string
     *
     * @throws \Exception
     */
    public static function dump_crontab(bool $project_scope = true, bool $suppress_win_exception = false) : string|bool
    {
        if(LayConfig::get_os() == "WINDOWS") {
            if($suppress_win_exception)
                return false;

            Exception::throw_exception("
            You can't use this class to create a cronjob in windows. 
            It might be added in the future, but it isn't there now. 
            You can pass `true` to this method to suppress this error.
            ");
        }

        $out = self::new()->get_crontab($project_scope);

        if(!$out)
            return false;

        return implode(PHP_EOL, $out);
    }

    public static function dump_db_file() : ?array
    {
        $dir = LayConfig::mk_tmp_dir();
        $file = $dir . self::CRON_JOBS_JSON;

        if(!file_exists($file))
            return null;

        return json_decode(file_get_contents($file), true);
    }

    private function cron_db() : string {
        $dir = LayConfig::mk_tmp_dir();
        $file = $dir . self::CRON_JOBS_JSON;
        $this->output_file = $dir . "cron_outputs.txt";

        if(!file_exists($file))
            file_put_contents($file, json_encode(self::DB_SCHEMA));

        if(!file_exists($this->output_file))
            file_put_contents($this->output_file, PHP_EOL);

        return $file;
    }

    private function db_data_init() : void {
        if(isset($this->jobs_list) && isset($this->report_email))
            return;

        $data = json_decode(file_get_contents($this->cron_db()), true);

        $this->jobs_list = $this->jobs_list ?? $data['jobs'] ?? [];
        $this->report_email = $this->report_email ?? $data['mailto'] ?? "";
    }

    private function db_data_clear_all() : void {
        $data = self::DB_SCHEMA;

        $this->jobs_list = $data['jobs'];
        $this->report_email = $data['mailto'];

        $this->commit();
    }

    private function db_job_by_id(string|int $uid) : ?string {
        $this->db_data_init();
        return $this->jobs_list[$uid] ?? null;
    }

    private function db_job_all() : array {
        $this->db_data_init();
        return $this->jobs_list;
    }

    /**
     * @return string[]
     *
     * @psalm-return array<string>
     */
    private function db_job_exists(string $job) : array {
        return LayArray::search($job, $this->db_job_all());
    }

    private function db_email_exists() : bool {
        return $this->report_email == $this->db_get_email();
    }

    private function db_get_email() : string {
        $this->db_data_init();
        return $this->report_email;
    }

    private function db_data_save() : bool {
        $data = self::DB_SCHEMA;
        $data['jobs'] = $this->jobs_list;
        $data['mailto'] = $this->report_email;

        return (bool) file_put_contents($this->cron_db(), json_encode($data));
    }

    private function project_server_jobs(string $mailto, string $cron_jobs) : string
    {
        $all_jobs = "";
        $app_id = LayConfig::app_id();
        $server_jobs = $this->get_crontab(false) ?? [];

        foreach ($server_jobs as $i => $job) {
            if(empty($job))
                continue;

            if($i == 0 && $job == 'MAILTO=""') {
                $all_jobs .= $mailto;
                continue;
            }

            $job_app_id = LayFn::extract_cli_tag(self::APP_ID_KEY, true, $job);
            $job_app_id = $job_app_id ? trim($job_app_id) : $job_app_id;

            if($job_app_id != $app_id) {
                $all_jobs .= $job . PHP_EOL;
            }
        }

        if(empty($all_jobs))
            $all_jobs = $mailto;

        $all_jobs .= $cron_jobs;

        return $all_jobs;
    }

    private function crontab_save() : int|bool {
        $mailto = $this->report_email ? 'MAILTO=' . $this->report_email : 'MAILTO=""';
        $mailto .= PHP_EOL;
        $cron_jobs = implode("", $this->jobs_list);

        $data = $this->project_server_jobs(
            mailto: $mailto,
            cron_jobs: $cron_jobs
        );

        $exec = @file_put_contents(self::TMP_CRONTAB_TXT, $data);

        if($exec) {
            exec("crontab '" . self::TMP_CRONTAB_TXT . "' 2>&1", $out);
            $exec = empty($out);
            $error = implode("\n", $out);
        }
        else
            $error = "Could not create cronjob. Confirm if you have access to crontab: " . self::TMP_CRONTAB_TXT;

        $this->exec_output = [
            "exec" => $exec,
            "msg" => !$exec ? $error  : "Cron job added successfully. Date: " . LayDate::date(format_index: 2)
        ];

        return $exec;
    }

    private function commit() : bool {
        return $this->crontab_save() && $this->db_data_save();
    }

    private function make_job(string $job) : string {
        $this->db_data_init();

        $server = LayConfig::server_data();

        $schedule = $this->minute . " " . $this->hour . " " . $this->day_of_the_month . " " . $this->month . " " . $this->day_of_the_week;

        $job_plain = $job;
        $job = $server->root . $job_plain;
        $job = self::php_bin() . " $job";

        $out = $schedule . " " . $job . PHP_EOL;

        if($this->just_once_set) {
            if(!isset($this->job_id))
                Exception::throw_exception("If using `just_once`, then `job_id` must be set");

            $out = JustOnce::yes($schedule, $job, $this->job_id);
            $this->just_once_set = false;
        }

        return $out;
    }

    private function add_job(string $job) : void {
        $job = rtrim($job, PHP_EOL) . " " . self::APP_ID_KEY . " " . LayConfig::app_id() . PHP_EOL;

        $job_exists = $this->db_job_exists($job)['found'];

        if(!$job_exists) {
            if(isset($this->job_id))
                $this->jobs_list[$this->job_id] = $job;
            else
                $this->jobs_list[] = $job;
        }

        $this->commit();
    }

    private function delete_job_by_id(string|int $uid) : bool {
        $this->db_data_init();

        $existed = isset($this->jobs_list[$uid]);

        if(!$existed)
            return true;

        unset($this->jobs_list[$uid]);
        return $this->commit();
    }

    private function delete_job_by_job(string $job) : bool {
        $this->db_data_init();

        $job = $this->make_job($job);
        $job = $this->db_job_exists($job);

        if(!$job['found'])
            return true;

        unset($this->jobs_list[$job['index'][0]]);
        return $this->commit();
    }

    private function handle_ranges_and_more(string $input, string $date_format) : string {
        $output = "";

        foreach (explode(",", $input) as $int) {
            if(str_contains($int, "-")) {
                $range = explode("-", $int);
                $res = date($date_format, strtotime($range[0])) . "-" . date($date_format, strtotime($range[1]));
            }
            else
                $res = date($date_format, strtotime($int));

            $res .= ",";

            if(str_contains($output, $res))
                continue;

            $output .= $res;
        }

        return rtrim($output, ",");
    }

    public static function new () : self {
        date_default_timezone_set(self::$time_zone);

        return new self();
    }

    public function job_id(string $uid) : self {
        $this->job_id = $uid;
        return $this;
    }

    public function log_output(string $output) : void
    {
        $this->cron_db();
        file_put_contents($this->output_file, $output . PHP_EOL, FILE_APPEND|LOCK_EX);
    }

    /**
     * Create a job that is scoped in the project.
     * The script to run has to be a php file, because the php binary will be prefixed.
     * Any script you add should be located with the project directory scope.
     * **Schedule** has to be set using any of the provided methods in this class
     *
     * @example bob composer_up
     * @param string $job
     * @return array
     * @return array{
     *      exec: bool,
     *      msg: string
     *  }
     */
    public function new_job(string $job) : array {
        $this->add_job($this->make_job($job));
        return $this->exec_output;
    }

    /**
     * @param string $job
     * @return void
     */
    public function print_job(string $job) : void {
        echo $this->make_job($job) . '<br/>';
    }

    /**
     * Create a job via raw command. You will specify the schedule and the script by yourself.
     *
     * @example * * * * * /var/www/html/remind-me.py
     * @param string $command
     * @param bool $add_eol
     * @return array
     * @return array{
     *      exec: bool,
     *      msg: string
     *  }
     */
    public function exec(string $command, bool $add_eol = true) : array {
        $this->add_job($command . ($add_eol ? PHP_EOL : ''));
        return $this->exec_output;
    }

    public function time_zone(string $time_zone) : self
    {
        self::$time_zone = $time_zone;
        return $this;
    }

    /**
     * (experimental) The email a cron report should be sent to
     * @param string $email
     * @return $this
     */
    public function report_email(string $email) : self {
        $this->report_email = $email;
        return $this;
    }

    public function list_jobs() : ?array {
        return $this->db_job_all();
    }

    /**
     * @param string|int $uid
     * @param bool $add_schedule
     *
     * @return null|string[]
     *
     * @psalm-return array{schedule: string, binary: string, script: string}|null
     */
    public function get_job(string|int $uid) : array|null {
        $job = $this->db_job_by_id($uid);

        if(!$job)
            return null;

        $x = explode(" ", $job, 7);

        return [
            "schedule" => "$x[0] $x[1] $x[2] $x[3] $x[4]",
            "binary" => $x[5],
            "script" => $x[6],
        ];
    }

    public function get_crontab(bool $project_scope = true) : ?array
    {
        exec("crontab -l 2>&1", $jobs);

        if(str_contains($jobs[0], "no crontab for"))
            return null;

        if(!$project_scope)
            return $jobs;

        $out = [$jobs[0]];

        $app_id = LayConfig::app_id();

        foreach ($jobs as $i => $job) {
            if($i == 0 && str_starts_with($job, "MAILTO"))
                continue;

            $job_app_id = LayFn::extract_cli_tag(self::APP_ID_KEY, true, $job);
            $job_app_id = $job_app_id ? trim($job_app_id, "'") : $job_app_id;

            if($app_id == $job_app_id)
                $out[] = $job;
        }

        return $out;
    }

    public function unset(string|int $uid_or_job) : bool {
        return $this->delete_job_by_id($uid_or_job) || $this->delete_job_by_job($uid_or_job);
    }

    public function unset_report_email() : void
    {
        $this->report_email = "";
        $this->commit();
    }

    public function clear_all() : void
    {
        $this->db_data_clear_all();
    }

    public function clear_log() : void
    {
        $this->cron_db();

        if(!file_exists($this->output_file))
            file_put_contents($this->output_file, PHP_EOL);
    }

    /**
     * Use this to create a cron job that executes only once.
     * Note that `job_id` is required when using this method
     * @return $this
     */
    public function just_once() : self
    {
        $this->just_once_set = true;
        return $this;
    }

    /**
     * Use the link below to see examples of how to schedule your cron job
     * @param string|null $minute
     * @param string|null $hour
     * @param string|null $day_of_the_month
     * @param string|null $month
     * @param string|null $day_of_the_week
     * @link https://crontab.guru/examples.html for examples
     * @return $this
     */
    public function schedule(?string $minute = null, ?string $hour = null, ?string $day_of_the_month = null, ?string $month = null, ?string $day_of_the_week = null) : self {
        $this->minute = $minute ?? $this->minute;
        $this->hour = $hour ?? $this->hour;
        $this->day_of_the_month = $day_of_the_month ?? $this->day_of_the_month;
        $this->month = $month ?? $this->month;
        $this->day_of_the_week = $day_of_the_week == '7' ? '0' : ($day_of_the_week ?? $this->day_of_the_week);

        return $this;
    }

    /**
     * Schedules jobs for every number of minutes indicated.
     * @param int $minute
     * @return $this
     *@see schedule
     * @example `5` minutes = every `5` minutes. i.e 5, 10, 15...n
     */
    public function every_minute(int $minute = 1) : self {
        $this->schedule(minute: "*/$minute");
        return $this;
    }

    /**
     * Schedules jobs for every number of hours indicated.
     * @param int $hour
     * @return $this
     *@see schedule
     * @example `2` hour = every `2` hours. i.e 2, 4, 6, 8...n
     */
    public function every_hour(int $hour = 1) : self {
        $this->schedule(hour: "*/$hour");
        return $this;
    }

    /**
     * 12-hours of every day.
     * Not to be mistaken for `every_hour` `every_minute`.
     * This method schedules the job for the specified `$hour:$minute am|pm` of every day;
     * except days are modified by the `weekly` method.
     * @param int $hour
     * @param int $minute
     * @param bool $am
     * @return $this
     * @see schedule
     */
    public function daily(int $hour = 12, int $minute = 0, bool $am = true) : self {
        $am = $am ? "am" : "pm";
        $date = explode(" ", date("G i", strtotime("$hour:$minute$am")));
        $this->schedule(minute: $date[1], hour: $date[0]);
        return $this;
    }

    /**
     * Schedules for all the days specified.
     * To tweak the time, you need to call the appropriate methods when building your job.
     * @param string $day_of_the_week accepts: mon, monday, Monday;
     * it could be a range or comma-separated values.
     * @return $this
     * @see schedule
     * @example `->weekly('mon - fri, sun')`
     */
    public function weekly(string $day_of_the_week) : self {
        $this->schedule(day_of_the_week: $this->handle_ranges_and_more($day_of_the_week, "w"));
        return $this;
    }

    /**
     * Schedules for specified days of every month.
     * To tweak the day and time, you need to call the appropriate methods when building your job.
     * @param string|int $days_of_the_month accepts: 1 - 31;
     * it could be an int, a range or comma-separated values.
     * @return $this
     * @throws \Exception
     *@see schedule
     */
    public function monthly(string|int $days_of_the_month = 1) : self {
        if(!is_int($days_of_the_month)) {
            $this->schedule(day_of_the_month: $this->handle_ranges_and_more($days_of_the_month, "j"));
            return $this;
        }

        if ($days_of_the_month > 31)
            Exception::throw_exception("Argument #1: Day of the month cannot be greater than 31", "CronBoundExceeded");

        if ($days_of_the_month < 1)
            Exception::throw_exception("Argument #1: Day of the month cannot be less than 1", "CronBoundExceeded");

        $this->schedule(day_of_the_month: $days_of_the_month);
        return $this;
    }

    /**
     * Schedules for all the months specified.
     * To tweak the day, month, and time, you need to call the appropriate methods when building your job.
     * @param string $months accepts: Jan, jan, January
     * it could be a range or comma-separated values.
     * @return $this
     *@see schedule
     */
    public function yearly(string $months) : self {
        $this->schedule(month: $this->handle_ranges_and_more($months, "n"));
        return $this;
    }

}