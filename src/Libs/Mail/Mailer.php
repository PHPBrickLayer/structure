<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Mail;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayDir;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ExpectedValues;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class Mailer {
    private static PHPMailer $mail_link;

    private static array $credentials = [
        "host" => null,
        "port" => null,
        "protocol" => null,
        "username" => null,
        "password" => null,
        "default_sender_email" => null,
        "default_sender_name" => null,
        "max_queue_items" => null,
        "max_queue_retries" => null,
    ];

    private array $attachment;
    private array $client;
    private array $bcc;
    private array $cc;
    private array $server;
    private array $server_from;
    private string $body;
    private string $subject;
    private bool $bypass_template = true;
    private bool $to_client = true;
    private bool $use_smtp = true;
    private bool $debug = false;
    private bool $send_on_dev_env = false;
    private string $log_data;

    private function collect_log(string $string, int $level) : void
    {
        $this->log_data .= "[X] $level >> $string\n";
    }

    private function dump_log() : void
    {
        if(!isset($this->log_data))
            return;

        $log = LayConfig::server_data()->temp . "emails" . DIRECTORY_SEPARATOR;
        LayDir::make($log, 0777, true);

        $log .= LayDate::now() . ".log";

        file_put_contents($log, "[" . LayDate::date(format_index: 3) . "]\n" . $this->log_data);
    }

    private function connect_smtp() : void {
        if(!$this->use_smtp)
            return;

        self::$mail_link->SMTPDebug = SMTP::DEBUG_SERVER;            //Enable verbose debug output
        self::$mail_link->isSMTP();                                      // Send using SMTP
        self::$mail_link->SMTPAuth   = true;                             // Enable SMTP authentication

        $this->log_data = "";
        self::$mail_link->Debugoutput = fn($str, $level) => $this->collect_log($str, $level);

//        if ($this->debug)
//            self::$mail_link->Debugoutput = "html";
//        else {
//            $this->log_data = "";
//            self::$mail_link->Debugoutput = fn($str, $level) => $this->collect_log($str, $level);
//        }

        try {
            self::$mail_link->SMTPSecure = self::$credentials['protocol'];   // Enable implicit TLS encryption
            self::$mail_link->Host       = self::$credentials['host'];       // Set the SMTP server to send through
            self::$mail_link->Port       = self::$credentials['port'];       // use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`
            self::$mail_link->Username   = self::$credentials['username'];
            self::$mail_link->Password   = self::$credentials['password'];
        }catch (\Exception $e){
            LayException::throw_exception("SMTP Credentials has not been setup. " . $e->getMessage(),"SMTPCredentialsError", exception: $e);
        }

    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    private function start_process() : array
    {
        if(!self::$credentials['host'])
            LayConfig::set_smtp();

        $site_data = LayConfig::new()::site_data();

        self::$mail_link = new PHPMailer();
        $email = $this->client['email'] ?? null;
        $name = $this->client['name'] ?? null;

        if((empty($email) || empty($name)) && $this->to_client)
            LayException::throw_exception("Sending an email <b>to a client</b> with an empty `email`: [$email] or `name`: [$name] is not allowed!. If you wish to send to the server, use `->to_server()` method.", "EmptyRequiredField");

        $this->server_from['email'] = $this->server_from['email'] ?? self::$credentials['default_sender_email'] ?? $site_data->mail->{0};
        $this->server_from['name'] = $this->server_from['name'] ?? self::$credentials['default_sender_name'] ?? $site_data->name->short;

        $this->server['email'] = $this->server['email'] ?? $site_data->mail->{0};
        $this->server['name'] = $this->server['name'] ?? $site_data->name->short;

        if($this->to_client) {
            $recipient = [
                "to" => $email,
                "name" => $name
            ];

            self::$mail_link->addReplyTo($this->server['email'], $this->server['name']);
        }
        else {
            $recipient = [
                "to" => $this->server['email'],
                "name" => $this->server['name']
            ];

            self::$mail_link->addReplyTo($email ?? $this->server['email'], $name ?? $this->server['name']);
        }

        if(@empty($this->subject))
            LayException::throw_exception("Sending an email with an empty `subject` is not allowed!", "EmptyRequiredField");

        self::$mail_link->Subject = $this->subject;

        if(@empty($this->body))
            LayException::throw_exception("Sending an email with an empty `body` is not allowed!", "EmptyRequiredField");

        $this->body = $this->bypass_template ? $this->body : $this->email_template($this->body);

        self::$mail_link->msgHTML($this->body);

        self::$mail_link->addAddress($recipient['to'], $recipient['name']);
        self::$mail_link->setFrom($this->server_from['email'], $this->server_from['name']);

        if(isset($this->bcc)) {
            foreach ($this->bcc as $bcc) {
                self::$mail_link->addBCC($bcc['email'], $bcc['name']);
            }
        }

        if(isset($this->cc)) {
            foreach ($this->cc as $cc) {
                self::$mail_link->addCC($cc['email'], $cc['name']);
            }
        }

        if(isset($this->attachment) && !empty($this->attachment['filename'])) {
            if($this->attachment['as_string'])
                self::$mail_link->addStringAttachment(
                    $this->attachment['data'],
                    $this->attachment['filename'],
                    $this->attachment['encoding'],
                    $this->attachment['type'],
                    $this->attachment['disposition'],
                );
            else {
                if(!file_exists($this->attachment['filename']))
                    LayException::throw_exception("The file you're trying to attach does not exist", "AttachmentNotFound");

                self::$mail_link->addAttachment(
                    $this->attachment['filename'],
                    $this->attachment['data'],
                    $this->attachment['encoding'],
                    $this->attachment['type'],
                    $this->attachment['disposition'],
                );
            }
        }

        return $recipient;
    }

    final public static function get_credentials() : array {
        return self::$credentials;
    }

    final public static function set_credentials(
        #[ArrayShape([
            "host" => 'string',
            "port" => 'string',
            "protocol" => 'string',
            "username" => 'string',
            "password" => 'string',
            "default_sender_name" => 'string',
            "default_sender_email" => 'string',
        ])] ?array $details = null
    ) : array {
        $details ??= [
            "host" => $_ENV['SMTP_HOST'] ?? 'localhost',
            "port" => $_ENV['SMTP_PORT'] ?? 587,
            "protocol" => $_ENV['SMTP_PROTOCOL'] ?? 'tls',
            "username" => $_ENV['SMTP_USERNAME'],
            "password" => $_ENV['SMTP_PASSWORD'],
            "default_sender_name" => $_ENV['DEFAULT_SENDER_NAME'] ?? null,
            "default_sender_email" => $_ENV['DEFAULT_SENDER_EMAIL'] ?? null,
            "max_queue_items" => $_ENV['SMTP_MAX_QUEUE_ITEMS'] ?? 5,
            "max_queue_retries" => $_ENV['SMTP_MAX_QUEUE_RETRIES'] ?? 3,
        ];

        return self::$credentials = $details;
    }

    /**
     * Override this method and create your own template for your projects.
     * @param string $message
     * @return string
     */
    public function email_template(string $message) : string {
        $text_color = "#000000";
        $bg_color = "transparent";
        $copyright = "&copy; " . date("Y");

        return <<<MSG
            <html lang="en"><body>
                <div style="background: $bg_color; color: $text_color; padding: 20px; min-height: 400px; max-width: 80%; margin: auto">
                    <div style="text-align: center; background: $bg_color; padding: 10px 5px">
                        <img src="https://github.com/PHPBrickLayer/structure/raw/main/src/static/img/lay-logo-github.png" 
                            alt="PhpBricklayer Logo" 
                            style="max-width: 85%; padding: 10px 10px 0"
                        >
                    </div>
                    <div style="
                        margin: 10px auto;
                        padding: 15px 0;
                        font-size: 16px;
                        line-height: 1.6;
                    ">$message</div>
                    <p style="text-align: center; font-size: 12px">$copyright</p>
                    <small>If you are the author of this application, please update the template for the emails. This is the default for this framework</small>
                </div>
            </body></html>
        MSG;
    }

    final public function client(string $email, string $name) : self {
        $this->client = ["email" => $email, "name" => $name];
        return $this;
    }

    final public function bcc(#[ArrayShape(['email' => 'string','name' => 'string'])] array ...$bcc) : self {
        $this->bcc = $bcc;
        return $this;
    }

    final public function cc(#[ArrayShape(['email' => 'string','name' => 'string'])] array ...$cc) : self {
        $this->cc = $cc;
        return $this;
    }

    final public function server(string $email, string $name) : self {
        $this->server = ["email" => $email, "name" => $name];
        return $this;
    }

    final public function server_from(string $email, string $name) : self {
        $this->server_from = ["email" => $email, "name" => $name];
        return $this;
    }

    final public function body(string $email_body, bool $bypass_template = false) : self {
        $this->body = $email_body;
        $this->bypass_template = $bypass_template;
        return $this;
    }

    final public function attachment (
        string          $filename,
        string          $string_or_name = null,
        string          $type = '',
        ?MailerEncoding  $encoding = MailerEncoding::ENCODING_BASE64,
        ?string          $disposition = "attachment",
        ?bool            $attach_as_string = true
    ) : self
    {
        $this->attachment = [
            "data" => $string_or_name,
            "filename" => $filename,
            "type" => $type,
            "encoding" => $encoding ? $encoding->value : MailerEncoding::ENCODING_BASE64->value,
            "disposition" => $disposition ?? "attachment",
            "as_string" => $attach_as_string,
        ];

        return $this;
    }

    final public function subject(string $email_subject) : self {
        $this->subject = $email_subject;
        return $this;
    }

    /**
     * Sends the email to the server, rather than the client.
     * @return bool The result of the queued email
     * @throws Exception
     */
    final public function to_server(bool $queue = true, int $priority = 0) : bool {
        $this->to_client = false;

        if(!$queue)
            return $this->send();

        return $this->queue($priority);
    }

    /**
     * Sends the email to the client. This is the default behaviour
     * @return bool The result of the queued email
     * @throws Exception
     */
    final public function to_client(bool $queue = true, int $priority = 0) : bool {
        $this->to_client = true;

        if(!$queue)
            return $this->send();

        return $this->queue($priority);
    }

    /**
     * Disable the use of smtp connection, maybe if you want to use the local mail server
     * @return $this
     */
    final public function not_smtp() : self {
        $this->use_smtp = false;
        return $this;
    }

    /**
     * Force the mail server to send an email on localserver
     * @return $this
     */
    final public function send_on_dev_env() : self {
        $this->send_on_dev_env = true;
        return $this;
    }

    final public function debug() : self {
        $this->debug = true;
        return $this;
    }

    final public function send() : ?bool
    {
        $recipient = $this->start_process();

        if($this->debug) {
            LayException::throw_exception(
                "[TO] " . $recipient['email'] . "<" . $recipient['name'] . ">\n<br>"
                . "[FROM] " . $this->server_from['email'] . "<" . $this->server_from['name'] . ">\n<br>"
                . "[SUBJECT] " . $this->subject . "\n<br>"
                . "[BODY] " . $this->body . "\n<br>"
                , "MailerDebug"
            );
        }

        try {
            $this->connect_smtp();

            if(LayConfig::$ENV_IS_PROD || $this->send_on_dev_env) {
                $send = self::$mail_link->send();
                $this->dump_log();
                return $send;
            }

            return true;

        } catch (\Exception $e) {
            LayException::throw_exception(
                htmlspecialchars($recipient['to']) . ' LayMail.php' . self::$mail_link->ErrorInfo,
                "MailerError",
                false,
                exception: $e
            );

            // Reset the connection to abort sending this message
            // If Loop the loop will continue trying to send to the rest of the list
            self::$mail_link->getSMTPInstance()->reset();
        }

        // If loop Clears all addresses and attachments for the next iteration
        self::$mail_link->clearAddresses();
        self::$mail_link->clearAttachments();
        self::$mail_link->clearBCCs();
        self::$mail_link->clearCCs();

        return false;
    }

    /**
     * @param int $priority Between 0 and 5.
     * 5 means push to the front of the queue.
     * 0 means take to the back of the queue
     *
     * @return bool|null
     * @throws Exception
     */
    final public function queue(#[ExpectedValues([0,1,2,3,4,5])] int $priority = 0) : ?bool {
        if($this->debug)
            $this->send();

        $this->start_process();

        return (new MailerQueueHandler())->add_to_queue([
            "cc" => json_encode($this->cc ?? []),
            "bcc" => json_encode($this->bcc ?? []),
            "attachment" => json_encode($this->attachment ?? []),

            "subject" => $this->subject,
            "body" => $this->body,

            "actors" => json_encode([
                "client" => $this->client,
                "server" => $this->server,
                "server_from" => $this->server_from,
                "send_to" => $this->to_client ? "TO_CLIENT" : "TO_SERVER",
            ]),

            "status" => MailerStatus::QUEUED->name,
            "priority" => $priority,
        ]);
    }
}
