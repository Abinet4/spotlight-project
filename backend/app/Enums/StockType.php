<?php

namespace App\Enums;

enum StockType: string
{
    case InStock = 'in_stock';
    // Retired: Betoch sells finished furniture only. Kept so ERP code and old records still read.
    case MadeToOrder = 'made_to_order';
}
