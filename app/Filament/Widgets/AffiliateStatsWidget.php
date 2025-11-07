<?php

namespace App\Filament\Widgets;

use App\Models\AffiliateEarning;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AffiliateStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getCards(): array
    {
        $totalEarnings = AffiliateEarning::where('status', 'completed')->sum('amount');
        $pendingEarnings = AffiliateEarning::where('status', 'pending')->sum('amount');
        $totalAffiliates = User::whereNotNull('affiliate_code')->count();
        $conversionRate = $this->calculateConversionRate();

        return [
            Stat::make('Total Affiliate Earnings', 'KES ' . number_format($totalEarnings, 2))
                ->description('Total completed earnings')
                ->icon('heroicon-m-trending-up')
                ->color('success')
                ->chart([7, 3, 4, 5, 6, 3, 5]), // Example chart data, adjust as needed

            Stat::make('Pending Earnings', 'KES ' . number_format($pendingEarnings, 2))
                ->description('Awaiting processing')
                ->icon('heroicon-m-clock')
                ->color('warning')
                ->chart([2, 3, 4, 3, 4, 3, 4]), // Example chart data, adjust as needed

            Stat::make('Active Affiliates', number_format($totalAffiliates))
                ->description('Total registered affiliates')
                ->icon('heroicon-m-users')
                ->color('primary')
                ->chart([3, 4, 5, 6, 7, 8, 9]), // Example chart data, adjust as needed

            Stat::make('Average Conversion Rate', number_format($conversionRate, 1) . '%')
                ->description('From clicks to sales')
                ->icon('heroicon-m-chart-bar')
                ->color($conversionRate > 2 ? 'success' : 'warning')
                ->chart([2, 3, 2, 4, 3, 4, 3]), // Example chart data, adjust as needed
        ];
    }

    private function calculateConversionRate(): float
    {
        $totalReferrals = User::whereNotNull('referred_by')->count();
        $totalConversions = AffiliateEarning::where('status', 'completed')
            ->distinct('referred_user_id')
            ->count();

        if ($totalReferrals === 0) return 0;

        return ($totalConversions / $totalReferrals) * 100;
    }
}