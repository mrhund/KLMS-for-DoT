<?php

namespace App\Entity;

enum ShopOrderHistoryAction : string
{
    case OrderCreated = 'order_created';
    case PaymentSuccessful = 'payment_successful';
    case PaymentFailed = 'payment_failed';
    case PaymentNotice = 'payment_notice';
    case OrderCanceled = 'payment_canceled';
}
