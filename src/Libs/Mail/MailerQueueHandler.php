<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Mail;

use BrickLayer\Lay\Libs\Cron\LayCron;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\Primitives\Traits\TableTrait;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\SQL;

final class MailerQueueHandler {

    use TableTrait;

    protected static string $table = "lay_mailer_queue";
    protected static string $SESSION_KEY = "LAY_MAILER";
    protected const JOB_UID = "lay-email-queue-handler";

    protected static function table_creation_query() : void
    {

        self::orm()->query(
            "CREATE TABLE IF NOT EXISTS " . self::$table . " (
                id char(36) NOT NULL PRIMARY KEY,
                created_at timestamp NOT NULL DEFAULT current_timestamp,
                updated_at timestamp NULL,
                created_by char(36) DEFAULT NULL,
                updated_by char(36) DEFAULT NULL,
                deleted integer DEFAULT 0,
                deleted_at timestamp DEFAULT NULL,
                deleted_by char(36) DEFAULT NULL,
                cc json DEFAULT NULL,
                bcc json DEFAULT NULL,
                attachment json DEFAULT NULL,
                subject varchar(100) NOT NULL,
                body text NOT NULL,
                actors json NOT NULL,
                status varchar(20) DEFAULT '" . MailerStatus::QUEUED->name . "',
                priority integer DEFAULT 0,
                retries integer DEFAULT 0,
                time_sent timestamp DEFAULT NULL
            )"
        );
    }

    /**
     * Deletes stale mails that have exceeded a specific timeframe; 15 days by default].
     * If the project requires storing sent mails, the first argument should be used to specify that
     *
     * @param bool $include_sent_mails
     * @param int $days_after
     * @return bool
     * @throws \Exception
     */
    private function hard_delete_mails(bool $include_sent_mails = true, int $days_after = 15) : bool
    {
        $orm = self::orm(self::$table);

        $orm->where(
            $orm->days_diff(LayDate::date(), "time_sent", cast: false),
            "> " . (SQL::get_driver() != OrmDriver::MYSQL ? 'INTERVAL' : ''),
            (string) max($days_after, 0)
        );

        $orm->or_where("time_sent", "IS", "NULL");

        if(!$include_sent_mails)
            $orm->and_where("status", "!=", MailerStatus::SENT->name);

        return $orm->delete();
    }

    public function has_queued_items() : bool
    {
        return (bool) self::orm(self::$table)
            ->where("deleted", "0")
            ->bracket(
                function (SQL $db){
                    return $db->where("status",  MailerStatus::QUEUED->name)
                        ->or_where("status", MailerStatus::RETRY->name);
                }, "AND"
            )
            ->count();
    }

    public function is_still_sending() : bool
    {
        return (bool) self::orm(self::$table)
            ->where("status", MailerStatus::SENDING->name)
            ->and_where("deleted", "0")
            ->count();
    }

    private function change_status(string $id, MailerStatus $status) : bool
    {
        $cols = [
            "status" => $status->name
        ];

        if($status == MailerStatus::SENT)
            $cols['time_sent'] = LayDate::date();

        return self::orm(self::$table)
            ->where("id='$id' AND deleted=0")
            ->column($cols)
            ->edit();
    }

    public function want_to_send(string $id) : bool
    {
        return $this->change_status($id, MailerStatus::SENDING);
    }

    public function want_to_retry(string $id, int $retries) : bool
    {
        return self::orm(self::$table)
            ->where("id='$id' AND deleted=0")
            ->column([
                "status" => MailerStatus::SENDING->name,
                "retries" => $retries + 1
            ])
            ->edit();
    }

    public function try_again(string $id) : bool
    {
        return $this->change_status($id, MailerStatus::RETRY);
    }

    public function failed_to_send(string $id) : bool
    {
        return $this->change_status($id, MailerStatus::FAILED);
    }

    public function email_sent(string $id) : bool
    {
        return $this->change_status($id, MailerStatus::SENT);
    }

    public function next_items() : array
    {
        return self::orm(self::$table)->loop()
            ->where("deleted", "0")
            ->bracket(
                function (SQL $db){
                    return $db->where("status",  MailerStatus::QUEUED->name)
                        ->or_where("status", MailerStatus::RETRY->name);
                }, "and"
            )
            ->sort("priority","desc")
            ->sort("created_at","asc")
            ->limit(LayFn::env('SMTP_MAX_QUEUE_ITEMS', 5))
            ->then_select();
    }

    public function add_to_queue(array $columns) : bool
    {
        $columns['id'] ??= "UUID()";
        $res = $this->new_record($columns);

        LayCron::new()
            ->job_id(self::JOB_UID)
            ->every_minute()
            ->new_job(".lay/workers/mail-processor.php");

        return $res;
    }

    public function stop_on_finish() : void
    {
        if(!$this->has_queued_items())
            LayCron::new()->unset(self::JOB_UID);
    }

    /**
     * @see hard_delete_mails()
     * @param bool $include_sent_mails
     * @return void
     * @throws \Exception
     */
    public function delete_stale_mails(bool $include_sent_mails = true) : void
    {
        $this->hard_delete_mails($include_sent_mails);
    }

}
