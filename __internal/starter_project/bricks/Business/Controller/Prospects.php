<?php

namespace Bricks\Business\Controller;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\Primitives\Traits\ControllerHelper;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use BrickLayer\Lay\Libs\Primitives\Traits\ValidateCleanMap;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use Bricks\Business\Model\Prospect;
use Utils\Email\Email;

class Prospects
{
    use IsSingleton, ControllerHelper, ValidateCleanMap;

    private static function model(): Prospect
    {
        return Prospect::new();
    }

    public function contact_us() : array {
        $post = self::request();

        if(
            (new Email())
                ->client($post->email, $post->name)
                ->subject("Enquiry: " . $post->subject)
                ->body($post->message)
                ->to_server()
        )
            self::res_success(
                "Your enquiry has been sent and a response will be given accordingly, please ensure to check your email for a response"
            );

        return self::res_error();
    }

    public function add(): array
    {
        self::vcm_start(self::request())
            ->vcm_rules([ 'required' => true ])
            ->vcm([ 'is_captcha' => true, 'field' => 'captcha' ])
            ->vcm([ 'field' => 'name' ])
            ->vcm([ 'field' => 'email', 'is_email' => true ])
            ->vcm([ 'field' => 'tel', 'is_num' => true, 'required' => false ])
            ->vcm([ 'field' => 'subject' ])
            ->vcm([ 'field' => 'message', 'clean' => [
                'strict' => false,
                'escape' => EscapeType::STRIP_TRIM_ESCAPE
            ]]);
        $post = self::vcm_end();

        if ($errors = self::vcm_errors(true))
            return self::res_warning($errors);

        $date = LayDate::date();

        $message = nl2br($post['message']);

        $body = [
            "subject" => $post['subject'],
            "body" => $message,
            "date" => $date,
        ];

        if ($data = self::model()->is_exist($post['name'], $post['email'])) {

            $data['body'] = @$data['body'] ? json_decode($data['body'], true) : [];
            $data['body'][] = $body;

            return $this->edit($data['id'], $data['body']);
        }

        $data = $post;
        $data['body'] = json_encode($body);
        $data['created_by'] = "END_USER";
        $data['created_at'] = $date;

        if (!self::model()->add($data))
            return self::res_error();

        (new Email())
            ->subject("Get Started: " . $post['subject'])
            ->body($post['message'])
            ->client($post['email'], $post['name'])
            ->server(
                LayConfig::site_data()->mail->{0},
                "Hello @ Osai Tech"
            )
            ->to_server();

        return self::res_success("Your request has been placed successfully. We will surely get back to you within 2 business working days. Thank you and best regards.");
    }

    private function edit(string $id, array $body): array
    {

        self::cleanse($id);
        $body = json_encode($body);

        self::cleanse($body);

        if (
            self::model()->edit(
                $id,
                [
                    "body" => $body,
                ]
            )
        )
            return self::res_success("Your request has been placed successfully. This is not your first rodeo. We will surely get back to you within 2 business working days. Thank you and best regards.");

        return self::res_error();
    }

    public function list(): array
    {
        return self::model()->list_100();
    }
}