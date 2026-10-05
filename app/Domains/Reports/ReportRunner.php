<?php

declare(strict_types=1);

namespace App\Domains\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Runs a report definition against a predefined data set. Every part of the
 * definition is checked against the whitelist in ReportDatasets; values are bound.
 */
final class ReportRunner
{
    public const MAX_ROWS = 5000;

    public const MAX_DAYS = 731;

    /**
     * @param  array<string, mixed>  $definition  {dataset, from, to, groups: list<string>, measures: list<string>, filters: array<string, string>, branch_id: ?int}
     * @return array{columns: list<array{key: string, label: string, money: bool}>, rows: list<array<string, mixed>>, truncated: bool}
     */
    public function run(array $definition): array
    {
        $d = $this->validate($definition);
        $set = ReportDatasets::all()[$d['dataset']];
        $q = DB::table($set['table'])->whereBetween($set['date'], [$d['from']->startOfDay(), $d['to']->endOfDay()]);
        foreach ($d['filters'] as $key => $value) {
            $q->where($set['filters'][$key]['column'], $value);
        }
        if ($d['branch_id'] !== null && $set['branch']) {
            $q->where($set['table'].'.branch_id', $d['branch_id']);
        }
        $selects = [];
        foreach ($d['groups'] as $i => $g) {
            // Whitelisted expressions from ReportDatasets only.
            // @phpstan-ignore argument.type (SQL comes only from the fixed ReportDatasets whitelist; user input is validated against it and never concatenated)
            $selects[] = new Expression($set['groups'][$g]['sql']." as g{$i}");
            // @phpstan-ignore argument.type (SQL comes only from the fixed ReportDatasets whitelist; user input is validated against it and never concatenated)
            $q->groupBy(new Expression($set['groups'][$g]['sql']));
        }
        foreach ($d['measures'] as $m) {
            // @phpstan-ignore argument.type (SQL comes only from the fixed ReportDatasets whitelist; user input is validated against it and never concatenated)
            $selects[] = new Expression($set['measures'][$m]['sql']." as m_{$m}");
        }
        $q->select($selects);
        foreach (array_keys($d['groups']) as $i) {
            $q->orderBy("g{$i}");
        }
        $raw = $q->limit(self::MAX_ROWS + 1)->get();

        $names = $this->names($d['groups'], $set, $raw);
        $rows = [];
        foreach ($raw->take(self::MAX_ROWS) as $r) {
            $row = [];
            foreach ($d['groups'] as $i => $g) {
                $v = $r->{"g{$i}"};
                $row[$g] = $names[$g][(string) $v] ?? ($v === null ? '—' : (string) $v);
            }
            foreach ($d['measures'] as $m) {
                $v = $r->{"m_{$m}"};
                $row[$m] = $set['measures'][$m]['money'] ? round(((int) $v) / 100, 2) : ($v === null ? null : (float) $v);
            }
            $rows[] = $row;
        }
        $columns = array_merge(
            array_map(fn ($g) => ['key' => $g, 'label' => $set['groups'][$g]['label'], 'money' => false], $d['groups']),
            array_map(fn ($m) => ['key' => $m, 'label' => $set['measures'][$m]['label'], 'money' => $set['measures'][$m]['money']], $d['measures']),
        );

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $raw->count() > self::MAX_ROWS];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{dataset: string, from: CarbonImmutable, to: CarbonImmutable, groups: list<string>, measures: list<string>, filters: array<string, string>, branch_id: ?int}
     */
    public function validate(array $definition): array
    {
        $sets = ReportDatasets::all();
        $dataset = (string) ($definition['dataset'] ?? '');
        if (! isset($sets[$dataset])) {
            throw ValidationException::withMessages(['dataset' => 'Choose a data set.']);
        }
        $set = $sets[$dataset];
        try {
            $from = CarbonImmutable::parse((string) ($definition['from'] ?? ''));
            $to = CarbonImmutable::parse((string) ($definition['to'] ?? ''));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['from' => 'Choose a date range.']);
        }
        if ($to->lt($from) || $from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose a range of up to two years.']);
        }
        $groups = array_values(array_unique(array_map('strval', (array) ($definition['groups'] ?? []))));
        $measures = array_values(array_unique(array_map('strval', (array) ($definition['measures'] ?? []))));
        if (count($groups) > 2 || array_diff($groups, array_keys($set['groups'])) !== []) {
            throw ValidationException::withMessages(['groups' => 'Group by up to two of the listed options.']);
        }
        if ($measures === [] || array_diff($measures, array_keys($set['measures'])) !== []) {
            throw ValidationException::withMessages(['measures' => 'Choose at least one total.']);
        }
        $filters = [];
        foreach ((array) ($definition['filters'] ?? []) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! isset($set['filters'][$key]) || (isset($set['filters'][$key]['values']) && ! in_array((string) $value, $set['filters'][$key]['values'], true))) {
                throw ValidationException::withMessages(['filters' => 'Use the listed filters only.']);
            }
            $filters[(string) $key] = mb_substr((string) $value, 0, 60);
        }
        $branch = isset($definition['branch_id']) && $definition['branch_id'] !== '' ? (int) $definition['branch_id'] : null;

        return ['dataset' => $dataset, 'from' => $from, 'to' => $to, 'groups' => $groups, 'measures' => $measures, 'filters' => $filters, 'branch_id' => $branch];
    }

    /**
     * Doctor and branch ids shown as names.
     *
     * @param  list<string>  $groups
     * @param  array<string, mixed>  $set
     * @param  Collection<int, \stdClass>  $raw
     * @return array<string, array<string, string>>
     */
    private function names(array $groups, array $set, Collection $raw): array
    {
        $out = [];
        foreach ($groups as $i => $g) {
            $ids = $raw->pluck("g{$i}")->filter()->unique()->values()->all();
            if ($g === 'doctor' && $ids !== []) {
                $out[$g] = DB::table('staff')->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => (string) $n])->all();
            } elseif ($g === 'branch' && $ids !== []) {
                $out[$g] = DB::table('branches')->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => (string) $n])->all();
            }
        }

        return $out;
    }
}
