<?php

namespace App\Http\Controllers;

use App\Services\System\ProcessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcessController extends Controller
{
    private const COLUMNS = [
        'pid' => 'PID',
        'ppid' => 'PPID',
        'user' => 'Usuario',
        'state' => 'Estado',
        'nice' => 'Nice',
        'cpu_percent' => 'CPU %',
        'memory_percent' => 'Memoria %',
        'memory_kb' => 'Memoria RSS (KB)',
        'command' => 'Comando',
    ];

    private const NUMERIC_COLUMNS = ['pid', 'ppid', 'nice', 'cpu_percent', 'memory_percent', 'memory_kb'];

    public function index(Request $request, ProcessService $processService): View
    {
        $query = $request->query('q', '');
        $query = is_string($query) ? trim($query) : '';
        $sort = $request->query('sort', 'pid');
        $direction = $request->query('direction', 'asc');

        if (! is_string($sort) || ! array_key_exists($sort, self::COLUMNS)) {
            $sort = 'pid';
            $direction = 'asc';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $processes = $processService->getProcesses();
        $hasProcesses = $processes !== [];

        if ($query !== '') {
            $processes = array_values(array_filter($processes, function (array $process) use ($query): bool {
                foreach (array_keys(self::COLUMNS) as $column) {
                    $value = in_array($column, ['cpu_percent', 'memory_percent'], true)
                        ? number_format($process[$column], 1, '.', '')
                        : (string) $process[$column];

                    if (mb_stripos($value, $query) !== false) {
                        return true;
                    }
                }

                return false;
            }));
        }

        usort($processes, function (array $left, array $right) use ($sort, $direction): int {
            $comparison = in_array($sort, self::NUMERIC_COLUMNS, true)
                ? $left[$sort] <=> $right[$sort]
                : strcmp(mb_strtolower($left[$sort]), mb_strtolower($right[$sort]));

            // Los empates mantienen un orden consistente por PID.
            return ($direction === 'desc' ? -$comparison : $comparison)
                ?: $left['pid'] <=> $right['pid'];
        });

        return view('processes.index', [
            'processes' => $processes,
            'q' => $query,
            'sort' => $sort,
            'direction' => $direction,
            'hasProcesses' => $hasProcesses,
            'columns' => self::COLUMNS,
            'numericColumns' => self::NUMERIC_COLUMNS,
        ]);
    }
}
