<?php
require 'vendor/autoload.php';
use AfricasTalking\SDK\AfricasTalking;

class Sms
{
    protected $phone;
    protected $AT;

    function __construct($phone)
    {
        $this->phone = $phone;
        $this->AT = new AfricasTalking("sandbox", "atsk_6936a588c857d9e88e813617e63338f7b61d42c81983c9e3e960075b0ce2bec0d2ddfd9e");
    }
    public function sendSMS($message, $recipients)
    {
        $sms = $this->AT->sms();
        $result = $sms->send([
            'username' => "sandbox",
            'to' => $recipients,
            'message' => $message,
            'from' => "29980"
        ]);

        return $result;

    }
}