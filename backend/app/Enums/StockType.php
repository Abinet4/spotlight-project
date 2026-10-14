<?php

namespace App\Enums;

enum StockType: string
{
    case InStock = 'in_stock';
    case MadeToOrder = 'made_to_order';
}
