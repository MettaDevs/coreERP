<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\Contracts\AuditColumns;
use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use App\Support\Modules\Contracts\DataClassificationRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Membaca klasifikasi data (gap 5, K-06, K-19) dari model tenant dan registry tabel tanpa model, lalu
 * mencocokkannya dengan skema database. Padanan AS0016 di Business Central.
 *
 * Yang dibaca: folder model Core (`CoreModelFolders`) dan `src/Models` setiap module. Model menyatakan bawaan tabel lewat
 * atribut {@see DataClassification} (dicari juga di kelas induk) dan timpaan kolom lewat konstanta
 * `COLUMN_CLASSIFICATION`. Kelas yang memakai {@see DataClassificationRegistry} menyatakan tabel yang
 * tidak punya model.
 *
 * @phpstan-type Declaration array{source: string, default: DataClass, columns: array<string, DataClass>}
 */
final class DataClassificationInspector
{
    /**
     * Potongan nama kolom yang biasanya menunjuk orang atau organisasi. Kolom yang namanya memuat salah
     * satunya wajib ditimpa eksplisit bila bawaan tabelnya `CustomerContent` atau `SystemMetadata`.
     * Dicocokkan per potongan yang dipisah `_`, supaya `catatan_teknisi` tidak terbaca `nik`.
     */
    public const PERSONAL_TOKENS = ['name', 'nama', 'email', 'phone', 'telepon', 'nik', 'npwp', 'birth', 'lahir', 'address', 'alamat'];

    /**
     * @param  array<string, Declaration>  $declarations
     * @param  array<string, string>  $undeclaredModels  tabel => model yang belum membawa atribut
     * @param  array<string, string>  $conflicts  tabel => keterangan tabel yang dinyatakan dua kali
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $declarations,
        private readonly array $undeclaredModels = [],
        private readonly array $conflicts = [],
    ) {}

    public static function fromCodebase(Connection $connection): self
    {
        $sources = CoreModelFolders::all();
        foreach (PemindaiModul::padaRepo()->folderModul() as $folder) {
            $sources[] = [$folder.'/src/Models', 'Modules\\'.PemindaiModul::namespaceModul($folder).'\\Models\\'];
        }

        $declarations = [];
        $undeclared = [];
        $conflicts = [];
        $add = static function (string $table, array $declaration) use (&$declarations, &$conflicts): void {
            if (isset($declarations[$table])) {
                $conflicts[$table] = "dinyatakan dua kali: {$declarations[$table]['source']} dan {$declaration['source']}";

                return;
            }
            $declarations[$table] = $declaration;
        };

        foreach ($sources as [$directory, $namespace]) {
            foreach (self::classesIn($directory, $namespace) as $class) {
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract()) {
                    continue;
                }

                if ($reflection->implementsInterface(DataClassificationRegistry::class)) {
                    /** @var class-string<DataClassificationRegistry> $class */
                    foreach ($class::tables() as $table => $entry) {
                        $add($table, ['source' => $class, 'default' => $entry['default'], 'columns' => $entry['columns'] ?? []]);
                    }

                    continue;
                }

                if (! $reflection->isSubclassOf(Model::class)) {
                    continue;
                }

                /** @var Model $model */
                $model = new $class;
                $default = self::defaultOf($reflection);
                if ($default === null) {
                    $undeclared[$model->getTable()] = $class;

                    continue;
                }

                /** @var array<string, DataClass> $columns */
                $columns = defined($class.'::COLUMN_CLASSIFICATION') ? constant($class.'::COLUMN_CLASSIFICATION') : [];
                $add($model->getTable(), ['source' => $class, 'default' => $default, 'columns' => $columns]);
            }
        }

        return new self($connection, $declarations, $undeclared, $conflicts);
    }

    /**
     * Klasifikasi efektif setiap kolom sebuah tabel: bawaan tabel, kolom jejak dari platform, lalu
     * timpaan tabel itu sendiri. Null bila tabelnya belum diklasifikasi.
     *
     * @return array<string, DataClass>|null
     */
    public function effective(string $table): ?array
    {
        $declaration = $this->declarations[$table] ?? null;
        if ($declaration === null) {
            return null;
        }

        $effective = [];
        foreach ($this->columnsOf($table) as $column) {
            $effective[$column] = $declaration['columns'][$column]
                ?? AuditColumns::COLUMN_CLASSIFICATION[$column]
                ?? $declaration['default'];
        }

        return $effective;
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $nameExceptions  kolom yang namanya memuat potongan data pribadi tetapi bukan
     * @return array<string, list<string>> tabel => masalahnya
     */
    public function problems(array $tables, array $nameExceptions = []): array
    {
        $problems = [];

        foreach ($tables as $table) {
            $found = [];
            $declaration = $this->declarations[$table] ?? null;

            if (isset($this->conflicts[$table])) {
                $found[] = $this->conflicts[$table];
            }

            if ($declaration === null) {
                $found[] = isset($this->undeclaredModels[$table])
                    ? "belum diklasifikasi: model {$this->undeclaredModels[$table]} belum membawa #[DataClassification]"
                    : 'belum diklasifikasi: tidak ada model berklasifikasi maupun entri registry';
                $problems[$table] = $found;

                continue;
            }

            if ($declaration['default'] === DataClass::ToBeClassified) {
                $found[] = 'bawaan tabel masih ToBeClassified';
            }

            $columns = $this->columnsOf($table);
            foreach ($declaration['columns'] as $column => $class) {
                if (! in_array($column, $columns, true)) {
                    $found[] = "timpaan menyebut kolom {$column} yang tidak ada";
                }
                if ($class === DataClass::ToBeClassified) {
                    $found[] = "kolom {$column} masih ToBeClassified";
                }
            }

            if (in_array($declaration['default'], [DataClass::CustomerContent, DataClass::SystemMetadata], true)) {
                foreach ($columns as $column) {
                    if (isset($declaration['columns'][$column]) || in_array($column, $nameExceptions, true)) {
                        continue;
                    }
                    if (array_intersect(explode('_', $column), self::PERSONAL_TOKENS) !== []) {
                        $found[] = "kolom {$column} terlihat seperti data pribadi tetapi ikut bawaan {$declaration['default']->value}; nyatakan eksplisit di COLUMN_CLASSIFICATION";
                    }
                }
            }

            if ($found !== []) {
                $problems[$table] = $found;
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return array_column($this->connection->select(
            'select column_name from information_schema.columns where table_schema = current_schema() and table_name = ? order by ordinal_position',
            [$table],
        ), 'column_name');
    }

    /** @param ReflectionClass<object> $reflection */
    private static function defaultOf(ReflectionClass $reflection): ?DataClass
    {
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $attributes = $current->getAttributes(DataClassification::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance()->default;
            }
        }

        return null;
    }

    /** @return list<class-string> */
    private static function classesIn(string $directory, string $namespace): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $directory)) + 1);
            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
