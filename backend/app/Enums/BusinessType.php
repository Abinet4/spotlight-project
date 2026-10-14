<?php

namespace App\Enums;

enum BusinessType: string
{
    case Shop = 'shop';
    case Manufacturer = 'manufacturer';
    case Workshop = 'workshop';
    case Individual = 'individual';
}
