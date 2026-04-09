<?php

namespace TEPay;

use Payments\Epoint\Epoint;
use Tutor\PaymentGateways\GatewayBase;

class EpointGateway extends GatewayBase
{
    public function get_root_dir_name(): string
    {
        return "Epoint";
    }

    public function get_payment_class(): string
    {
        return Epoint::class;
    }

    public function get_config_class(): string
    {
        return EpointConfig::class;
    }

    public static function get_autoload_file(): string
    {
        return "";
    }
}
