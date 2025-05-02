<?php

namespace Bricks\Business\Controller;

use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\Primitives\Traits\ControllerHelper;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use BrickLayer\Lay\Libs\Primitives\Traits\ValidateCleanMap;
use Bricks\Business\Model\Newsletter;
use Utils\Email\Email;

class Newsletters
{
    use IsSingleton, ControllerHelper, ValidateCleanMap;

    public function list(): array
    {
        return self::model()->list_100();
    }

    private static function model(): Newsletter
    {
        return Newsletter::new();
    }

    public function add(): array
    {
        self::vcm_start(self::request())
            ->vcm(['field' => 'name',])
            ->vcm(['field' => 'email', 'required' => true, 'is_email' => true ]);

        $post = self::vcm_end();

        if ($errors = self::vcm_errors(true))
            return self::res_warning($errors);


        if (self::model()->is_exist($post['email']))
            return self::res_warning("You have already subscribed, don't worry you will begin to see great tips, offers and freebies from us. We only dish out the best content and we don't spam");

        $post['created_by'] = "END_USER";
        $post['created_at'] = LayDate::date();

        if (!self::model()->add($post))
            return self::res_error();

        (new Email())->welcome_newsletter([
            "name" => $post['name'],
            "email" => $post['email'],
        ]);

        return self::res_success("Congratulations! You have successfully subscribed! We will get in touch with you  with the latest tips, tricks, offers and freebies. We only dish out quality content, we don't spam");
    }
}