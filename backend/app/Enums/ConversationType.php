<?php

namespace App\Enums;

enum ConversationType: string
{
    case Question = 'question';
    case Availability = 'availability';
    case Negotiation = 'negotiation';
    case CustomRequest = 'custom_request';

    public function label(): string
    {
        return match ($this) {
            self::Question => 'Question',
            self::Availability => 'Availability',
            self::Negotiation => 'Price offer',
            self::CustomRequest => 'Custom request',
        };
    }
}
