<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    use HasFactory;

    protected $fillable = ['gateway', 'revenue_share'];

    /**
     * Platform revenue share (percent of gross, 0–100) stored for gateway `mpesa`.
     * Quiz-master share of gross is (100 - this value), applied after affiliate deductions in {@see \App\Services\TransactionService::processPayment}.
     */
    public static function platformRevenueSharePercent(): float
    {
        $setting = static::query()->firstOrCreate(
            ['gateway' => 'mpesa'],
            ['revenue_share' => 0]
        );

        if ($setting->revenue_share === null) {
            throw new \RuntimeException('Platform revenue share (payment_settings.revenue_share) is not set.');
        }

        $p = (float) $setting->revenue_share;
        if ($p < 0 || $p > 100) {
            throw new \RuntimeException('Platform revenue share must be between 0 and 100.');
        }

        return $p;
    }

    /** Percent of gross (after affiliate) credited to quiz masters: 100 - platform share. */
    public static function quizMasterRevenueSharePercent(): float
    {
        return round(100.0 - self::platformRevenueSharePercent(), 4);
    }
}
