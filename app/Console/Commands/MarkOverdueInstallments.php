<?php

namespace App\Console\Commands;

use App\Services\Loans\OverdueService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loans:mark-overdue {--date= : Evaluate as of this date (Y-m-d); defaults to today}')]
#[Description('Flag installments past their grace period as overdue and charge late fees')]
class MarkOverdueInstallments extends Command
{
    public function handle(OverdueService $overdue): int
    {
        $asOf = $this->option('date') ? CarbonImmutable::parse($this->option('date'))->startOfDay() : CarbonImmutable::today();

        $total = $overdue->markAllOverdue($asOf);

        $this->info("Marked {$total} installment(s) overdue as of {$asOf->toDateString()}.");

        return self::SUCCESS;
    }
}
