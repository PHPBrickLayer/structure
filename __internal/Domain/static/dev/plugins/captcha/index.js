const captchaImgSrc = $lay.src.static_env + "plugins/captcha/img.php"

function reloadCaptcha(){
    $loop($sela(".captcha-holder"), captcha => {
        captcha.src = captchaImgSrc + "?t=" + Math.random() + new Date()
    })
}