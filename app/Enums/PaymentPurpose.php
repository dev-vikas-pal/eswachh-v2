<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    case Subscription = 'subscription';
    case ClothTopUp = 'cloth_topup';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'Subscription',
            self::ClothTopUp => 'Cloth top up',
        };
    }
}
