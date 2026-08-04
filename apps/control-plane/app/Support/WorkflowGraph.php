<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class WorkflowGraph
{
    public const KINDS = ['start', 'end', 'approval', 'manual_task', 'condition', 'parallel'];

    /** @return array{nodes:list<array<string,mixed>>,edges:list<array<string,mixed>>} */
    public static function starter(?array $assignee = null): array
    {
        $approval = [
            'assignee' => $assignee,
            'completion_policy' => 'single',
            'if_no_assignee' => 'reject',
        ];

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => ['label' => 'Mulai', 'config' => []], 'position' => ['x' => 80, 'y' => 180]],
                ['id' => 'approval-1', 'type' => 'approval', 'data' => ['label' => 'Persetujuan', 'config' => $approval], 'position' => ['x' => 360, 'y' => 180]],
                ['id' => 'end', 'type' => 'end', 'data' => ['label' => 'Selesai', 'config' => []], 'position' => ['x' => 680, 'y' => 180]],
            ],
            'edges' => [
                ['id' => 'start-approval-1', 'source' => 'start', 'target' => 'approval-1', 'outcome' => null, 'condition' => null],
                ['id' => 'approval-1-end', 'source' => 'approval-1', 'target' => 'end', 'outcome' => 'approve', 'condition' => null],
            ],
        ];
    }

    /** @param Collection<int, object> $elements @param Collection<int, object> $transitions */
    public static function validateRows(Collection $elements, Collection $transitions): void
    {
        $nodes = $elements->keyBy('key');
        $nodesById = $elements->keyBy('id');
        $errors = [];
        $starts = $nodes->where('kind', 'start');
        $ends = $nodes->where('kind', 'end');

        if ($nodes->isEmpty()) {
            $errors['graph'] = 'Tambahkan minimal satu alur workflow.';
        }
        if ($starts->count() !== 1) {
            $errors['start'] = 'Workflow harus memiliki tepat satu langkah Mulai.';
        }
        if ($ends->isEmpty()) {
            $errors['end'] = 'Workflow harus memiliki minimal satu langkah Selesai.';
        }

        $outgoing = $transitions->groupBy('from_element_id');
        $incoming = $transitions->groupBy('to_element_id');
        foreach ($nodes as $node) {
            if (! in_array($node->kind, self::KINDS, true)) {
                $errors["node.{$node->key}"] = "Elemen {$node->label} tidak didukung.";
                continue;
            }
            if ($node->kind !== 'start' && ! $incoming->has($node->id)) {
                $errors["node.{$node->key}"] = "Elemen {$node->label} belum terhubung dari langkah sebelumnya.";
            }
            if ($node->kind === 'start' && $incoming->has($node->id)) {
                $errors["node.{$node->key}"] = 'Langkah Mulai tidak boleh memiliki koneksi masuk.';
            }
            if ($node->kind !== 'end' && ! $outgoing->has($node->id)) {
                $errors["node.{$node->key}"] = "Elemen {$node->label} belum memiliki langkah berikutnya.";
            }
            if ($node->kind === 'end' && $outgoing->has($node->id)) {
                $errors["node.{$node->key}"] = 'Langkah Selesai tidak boleh memiliki koneksi keluar.';
            }
            if ($node->kind === 'condition') {
                $outcomes = $outgoing->get($node->id, collect())->pluck('outcome')->filter()->unique()->values()->all();
                if (! in_array('true', $outcomes, true) || ! in_array('false', $outcomes, true)) {
                    $errors["node.{$node->key}"] = "Keputusan kondisi {$node->label} harus memiliki cabang benar dan salah.";
                }
            }
            if ($node->kind === 'parallel' && $outgoing->get($node->id, collect())->count() < 2) {
                $errors["node.{$node->key}"] = "Cabang paralel {$node->label} harus memiliki minimal dua cabang.";
            }
        }

        foreach ($transitions as $transition) {
            if (! $nodesById->has($transition->from_element_id) || ! $nodesById->has($transition->to_element_id)) {
                $errors['edges'] = 'Ada koneksi yang menunjuk ke elemen yang sudah tidak ada.';
                break;
            }
        }

            if ($starts->count() === 1) {
            $reachable = self::walk($starts->first()->id, $outgoing);
            foreach ($nodes as $node) {
                if (! isset($reachable[$node->id])) {
                    $errors["node.{$node->key}"] = "Elemen {$node->label} tidak dapat dicapai dari Mulai.";
                }
            }
            if (self::hasCycle($starts->first()->id, $outgoing)) {
                $errors['graph'] = 'Workflow tidak boleh memiliki putaran yang tidak berujung.';
            }
            if ($ends->isNotEmpty()) {
                $reverse = collect();
                foreach ($transitions as $transition) {
                    $reverse->push((object) ['from_element_id' => $transition->to_element_id, 'to_element_id' => $transition->from_element_id]);
                }
                $canReachEnd = self::walk($ends->first()->id, $reverse->groupBy('from_element_id'));
                foreach ($nodes as $node) {
                    if (! isset($canReachEnd[$node->id])) {
                        $errors["node.{$node->key}"] = "Elemen {$node->label} tidak memiliki jalur menuju Selesai.";
                    }
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param Collection<int, Collection<int, object>> $outgoing @return array<string, bool> */
    private static function walk(string $start, Collection $outgoing): array
    {
        $seen = [];
        $stack = [$start];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($outgoing->get($current, collect()) as $edge) {
                $stack[] = $edge->to_element_id;
            }
        }

        return $seen;
    }

    /** @param Collection<string, Collection<int, object>> $outgoing */
    private static function hasCycle(string $start, Collection $outgoing): bool
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $node) use (&$visit, &$visiting, &$visited, $outgoing): bool {
            if (isset($visiting[$node])) {
                return true;
            }
            if (isset($visited[$node])) {
                return false;
            }
            $visiting[$node] = true;
            foreach ($outgoing->get($node, collect()) as $edge) {
                if ($visit($edge->to_element_id)) {
                    return true;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;

            return false;
        };

        return $visit($start);
    }
}
