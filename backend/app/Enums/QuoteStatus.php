<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    // Never stored: reported when a pending quote is past valid_until.
    case Expired = 'expired';
}
