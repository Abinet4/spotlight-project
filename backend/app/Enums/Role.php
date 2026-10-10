<?php

namespace App\Enums;

enum Role: string
{
    case Customer = 'customer';
    case Seller = 'seller';
    case Admin = 'admin';
    case Delivery = 'delivery';
}
