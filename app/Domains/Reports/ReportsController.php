<?php

declare(strict_types=1);

namespace App\Domains\Reports;

use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Report builder: predefined data sets only, totals only, each data set behind its existing permission.
 * Saving and scheduling are part of the higher packages.
 */
class ReportsController extends Controller
{
    public function index(Request $request): Response
    {
        $sets = collect(ReportDatasets::all())->filter(fn ($s) => Gate::allows($s['permission']));
        abort_if($sets->isEmpty(), 403);

        return Inertia::render('Reports/Builder', [
            'datasets' => $sets->map(fn ($s, $k) => ['key' => $k, 'label' => $s['label'], 'branch' => $s['branch'],
                'groups' => collect($s['groups'])->map(fn ($g, $gk) => ['key' => $gk, 'label' => $g['label']])->values(),
                'measures' => collect($s['measures'])->map(fn ($m, $mk) => ['key' => $mk, 'label' => $m['label']])->values(),
                'filters' => collect($s['filters'])->map(fn ($f, $fk) => ['key' => $fk, 'label' => $f['label'], 'values' => $f['values'] ?? null])->values()])->values(),
            'saved' => DB::table('report_definitions')->orderBy('name')->get()->filter(fn ($r) => $sets->has((string) (json_decode((string) $r->definition, true)['dataset'] ?? '')))
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'definition' => json_decode((string) $r->definition, true), 'schedule' => $r->schedule,
                    'recipients' => json_decode((string) ($r->recipients ?? '[]'), true)])->values(),
            'advanced' => $this->advanced(),
            'branches' => DB::table('branches')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'staff' => DB::table('staff')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function run(Request $request, ReportRunner $runner): JsonResponse
    {
        $definition = (array) $request->input('definition', []);
        $this->authorizeDataset($definition);

        return response()->json($runner->run($definition));
    }

    public function export(Request $request, string $format, ReportRunner $runner): HttpResponse
    {
        $definition = (array) json_decode((string) $request->input('definition', '{}'), true);
        $this->authorizeDataset($definition);
        $result = $runner->run($definition);
        $name = 'report-'.($definition['dataset'] ?? 'data').'-'.now()->format('Ymd-His');
        activity('reports')->withProperties(['dataset' => $definition['dataset'] ?? null, 'format' => $format, 'rows' => count($result['rows'])])->log('Report exported');
        if ($format === 'pdf') {
            $pdf = new Dompdf;
            $pdf->loadHtml(self::html($result, (string) ($request->input('title') ?: 'Report')));
            $pdf->setPaper('A4', count($result['columns']) > 5 ? 'landscape' : 'portrait');
            $pdf->render();

            return response((string) $pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\""]);
        }
        $out = fopen('php://temp', 'r+');
        abort_if($out === false, 500, 'Could not prepare the export.');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel opens it correctly
        fputcsv($out, array_map(fn ($c) => $c['label'], $result['columns']));
        foreach ($result['rows'] as $row) {
            fputcsv($out, array_map(fn ($c) => self::cell($row[$c['key']] ?? ''), $result['columns']));
        }
        rewind($out);

        return response((string) stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""]);
    }

    public function save(Request $request, ReportRunner $runner): RedirectResponse
    {
        abort_unless($this->advanced(), 403, 'Saved and scheduled reports are part of the Standard and Pro packages.');
        $data = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'], 'definition' => ['required', 'array'],
            'schedule' => ['required', 'in:none,weekly,monthly'], 'recipients' => ['array'], 'recipients.*' => ['integer']]);
        $this->authorizeDataset($data['definition']);
        $runner->validate($data['definition']);
        $permission = ReportDatasets::all()[(string) $data['definition']['dataset']]['permission'];
        $recipients = array_values(array_filter(array_map('intval', $data['recipients'] ?? []), fn (int $id) => self::staffMay($id, $permission)));
        $row = ['name' => trim($data['name']), 'definition' => json_encode($data['definition']), 'schedule' => $data['schedule'], 'recipients' => json_encode($recipients), 'updated_at' => now()];
        isset($data['id']) ? DB::table('report_definitions')->where('id', (int) $data['id'])->update($row)
            : DB::table('report_definitions')->insert($row + ['created_by' => (int) $request->user()?->getAuthIdentifier(), 'created_at' => now()]);

        return back()->with('success', 'Report saved.');
    }

    public function destroy(int $report): RedirectResponse
    {
        abort_unless($this->advanced(), 403);
        $def = (array) json_decode((string) DB::table('report_definitions')->where('id', $report)->value('definition'), true);
        $this->authorizeDataset($def);
        DB::table('report_definitions')->where('id', $report)->delete();

        return back()->with('success', 'Report deleted.');
    }

    /** Only staff whose role grants the data set's permission may receive it. */
    public static function staffMay(int $userId, string $permission): bool
    {
        $user = User::query()->find($userId);
        $member = Membership::query()->where('tenant_id', tenant('id'))->where('user_id', $userId)->usable()->exists();

        // The same permission check the app uses, so practice-customised permissions are respected.
        return $user instanceof User && $member && Gate::forUser($user)->allows($permission);
    }

    /**
     * @param  array{columns: list<array{key: string, label: string, money: bool}>, rows: list<array<string, mixed>>, truncated: bool}  $result
     */
    public static function html(array $result, string $title, int $limit = 0): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $rows = $limit > 0 ? array_slice($result['rows'], 0, $limit) : $result['rows'];
        $h = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 10px"><h2>'.$e($title).'</h2><table cellpadding="4" style="border-collapse: collapse; width: 100%"><tr>';
        foreach ($result['columns'] as $c) {
            $h .= '<th style="border-bottom: 1px solid #999; text-align: left">'.$e($c['label']).'</th>';
        }
        $h .= '</tr>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($result['columns'] as $c) {
                $v = $r[$c['key']] ?? '';
                $h .= '<td>'.$e($c['money'] && is_numeric($v) ? 'R '.number_format((float) $v, 2, '.', ' ') : self::cell($v)).'</td>';
            }
            $h .= '</tr>';
        }

        return $h.'</table>'.($result['truncated'] || ($limit > 0 && count($result['rows']) > $limit) ? '<p>More rows are available in Clinic Flow.</p>' : '').'</body></html>';
    }

    private static function cell(mixed $v): string
    {
        return is_float($v) && floor($v) === $v ? (string) (int) $v : (string) $v;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function authorizeDataset(array $definition): void
    {
        $set = ReportDatasets::all()[(string) ($definition['dataset'] ?? '')] ?? null;
        abort_if($set === null, 422, 'Choose a data set.');
        $this->authorize($set['permission']);
    }

    private function advanced(): bool
    {
        $provider = tenant();
        $package = $provider instanceof Provider ? Subscription::query()->where('tenant_id', $provider->id)->latest('id')->first()?->package : null;

        return $package !== null && $package->hasFeature('reports_advanced');
    }
}
