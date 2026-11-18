<?php

declare(strict_types=1);

namespace App\Domains\Finance\Console;

use App\Domains\Finance\Accounting\AccountingApp;
use App\Domains\Finance\Accounting\AccountingConnection;
use App\Domains\Finance\Accounting\JournalExporter;
use App\Domains\Finance\Accounting\PlatformAccountingConnection;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class AccountingExportCommand extends Command
{
    protected $signature = 'accounting:export {frequency=daily}';

    protected $description = 'Post daily journals to connected accounting apps (providers and the platform)';

    public function handle(JournalExporter $exporter): int
    {
        $frequency = (string) $this->argument('frequency');
        $platform = PlatformAccountingConnection::query()->where('enabled', true)->where('auto_export', $frequency)->first();
        $platformApp = $platform === null ? null : AccountingApp::query()->where('driver', $platform->driver->value)->first();
        if ($platform instanceof PlatformAccountingConnection && $platformApp instanceof AccountingApp) {
            $exporter->run($platformApp, $platform, fn (string $d) => JournalExporter::platformTotals($d));
        }
        Provider::query()->each(fn (Provider $p) => $p->run(function () use ($exporter, $frequency): void {
            $c = AccountingConnection::query()->where('enabled', true)->where('auto_export', $frequency)->first();
            $app = $c === null ? null : AccountingApp::query()->where('driver', $c->driver->value)->where('offered', true)->first();
            if ($c instanceof AccountingConnection && $app instanceof AccountingApp) {
                $exporter->run($app, $c, fn (string $d) => JournalExporter::providerTotals($d));
            }
        }));

        return self::SUCCESS;
    }
}
