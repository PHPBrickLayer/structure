<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Mail;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Libs\Abstract\TableTrait;
use BrickLayer\Lay\Libs\Cron\CronController;
use BrickLayer\Lay\Libs\Cron\LayCron;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayDir;
use JetBrains\PhpStorm\ArrayShape;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class MailerQueueHandler {

    use TableTrait;

    protected static string $table = "lay_mailer_queue";
    protected static string $SESSION_KEY = "LAY_MAILER";
    protected const JOB_UID = "lay-email-queue-handler";

    protected static function table_creation_query() : void
    {
        self::orm()->query("CREATE TABLE IF NOT EXISTS `" . self::$table . "` (
              `id` char(36) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
              `created_by` char(36) DEFAULT NULL,
              `updated_by` char(36) DEFAULT NULL,
              `deleted` int(1) DEFAULT 0,
              `deleted_at` datetime DEFAULT NULL,
              `deleted_by` char(36) DEFAULT NULL,
              `cc` json DEFAULT NULL,
              `bcc` json DEFAULT NULL,
              `attachment` json DEFAULT NULL,
              `subject` varchar(100) NOT NULL,
              `body` text NOT NULL,
              `actors` json NOT NULL,
              `status` varchar(20) DEFAULT 'QUEUED',
              `priority` int(1) DEFAULT 0,
              `retries` int(1) DEFAULT 0,
              `time_sent` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_status` (`status`) USING BTREE
            )
        ");
    }

    public function has_queued_items() : bool
    {
        return (bool) self::orm(self::$table)->where("(`status`='" . MailerStatus::QUEUED->name . "' OR `status`='" . MailerStatus::RETRY->name . "') AND deleted=0")->count_row();
    }

    public function is_still_sending() : bool
    {
        return (bool) self::orm(self::$table)->where("`status`='" . MailerStatus::SENDING->name . "' AND deleted=0")->count_row();
    }

    private function change_status(string $id, MailerStatus $status) : bool
    {
        return self::orm(self::$table)
            ->where("id='$id' AND deleted=0")
            ->column([
                "status" => $status->name
            ])
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
                "status" => MailerStatus::SENDING,
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
            ->where("(`status`='" . MailerStatus::QUEUED->name . "' OR `status`='" . MailerStatus::RETRY->name . "') AND deleted=0")
            ->sort("priority","desc")
            ->sort("created_at","asc")
            ->limit($_ENV['SMTP_MAX_QUEUE_ITEMS'] ?? 5)
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

}
