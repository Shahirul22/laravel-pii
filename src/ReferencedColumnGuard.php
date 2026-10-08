<?php

namespace Shahirul22\LaravelPiiSanitizer;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Shahirul22\LaravelPiiSanitizer\Exceptions\InvalidConfigurationException;
use Shahirul22\LaravelPiiSanitizer\Exceptions\UnsafeColumnException;
use Shahirul22\LaravelPiiSanitizer\Values\Keyed;

/**
 * Run-level checks 4 to 7 for mirror groups
 * (docs/design/referenced-identifier-structured-column-sanitization/spec,
 * "Boot-time checks"). A group is the connected set of columns linked by
 * Sanitizer::mirrors() entries across every target of one run. FK metadata is
 * read here only to refuse a run, never to add a member or choose a value
 * (R1.4: no relational inference).
 */
final class ReferencedColumnGuard
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ForeignKeyInspector $foreignKeys,
        private readonly ForeignKeySuspender $suspender,
    ) {}

    /**
     * Run-level mirror-group checks 4 to 7. Reads declarations and FK metadata only.
     *
     * Returns before calling fields() or any schema method when no target
     * declares a mirror, so a v1 run (R1.5) pays nothing for this guard.
     *
     * @param  list<array{label: string, model: Model, sanitizer: ?Sanitizer}>  $targets
     * @return list<Connection> connections whose FK enforcement must be suspended for this run
     *
     * @throws InvalidConfigurationException
     * @throws UnsafeColumnException
     */
    public function assertGroups(array $targets): array
    {
        /** @var array<int, array<string, list<string>>> $mirrorsByTarget */
        $mirrorsByTarget = [];

        foreach ($targets as $index => $target) {
            if ($target['sanitizer'] === null) {
                continue;
            }

            $mirrors = $target['sanitizer']->mirrors();

            if ($mirrors !== []) {
                $mirrorsByTarget[$index] = $mirrors;
            }
        }

        if ($mirrorsByTarget === []) {
            return [];
        }

        // Index every column a target of this run declares in fields(),
        // keyed "{table}.{column}" with the unprefixed name getTable() reports.
        /** @var array<int, array<string, mixed>> $fieldsByTarget */
        $fieldsByTarget = [];

        /** @var array<string, list<int>> $declared */
        $declared = [];

        foreach ($targets as $index => $target) {
            if ($target['sanitizer'] === null) {
                continue;
            }

            $fieldsByTarget[$index] = $target['sanitizer']->fields();

            foreach (array_keys($fieldsByTarget[$index]) as $column) {
                $declared[$target['model']->getTable().'.'.$column][] = $index;
            }
        }

        // Check 4 and linking.
        /** @var array<string, string> $parent union-find over nodes ("{table}.{column}") */
        $parent = [];

        /** @var list<string> $nodes first-seen order */
        $nodes = [];

        /** @var array<string, class-string<Sanitizer>> $listedBy the first sanitizer whose mirrors() linked the node */
        $listedBy = [];

        foreach ($mirrorsByTarget as $index => $mirrors) {
            $sanitizer = $targets[$index]['sanitizer'];
            $table = $targets[$index]['model']->getTable();

            if ($sanitizer === null) {
                continue;
            }

            foreach ($mirrors as $column => $list) {
                $source = $table.'.'.$column;

                if (count($declared[$source] ?? []) > 1) {
                    throw InvalidConfigurationException::mirrorAmbiguous($source);
                }

                $this->touch($source, $parent, $nodes);
                $listedBy[$source] ??= $sanitizer::class;

                foreach ($list as $mirror) {
                    if (! isset($declared[$mirror])) {
                        throw InvalidConfigurationException::mirrorNotInRun($sanitizer::class, $column, $mirror);
                    }

                    if (count($declared[$mirror]) > 1) {
                        throw InvalidConfigurationException::mirrorAmbiguous($mirror);
                    }

                    $this->touch($mirror, $parent, $nodes);
                    $listedBy[$mirror] ??= $sanitizer::class;
                    $this->union($source, $mirror, $parent);
                }
            }
        }

        /** @var array<string, list<string>> $groups root => member nodes, in first-seen order */
        $groups = [];

        foreach ($nodes as $node) {
            $groups[$this->find($node, $parent)][] = $node;
        }

        // Check 5: every member Keyed, one namespace per group.
        foreach ($groups as $members) {
            $this->assertKeyedGroup($members, $targets, $fieldsByTarget, $declared, $listedBy);
        }

        // Check 6: FK closure. Check 7 follows for the connections that need it.
        /** @var array<string, Connection> $suspend */
        $suspend = [];

        foreach ($groups as $members) {
            $this->assertForeignKeyClosure($members, $targets, $declared, $suspend);
        }

        foreach ($suspend as $connection) {
            $this->suspender->assertSuspendable($connection);
        }

        return array_values($suspend);
    }

    /**
     * @param  list<string>  $members
     * @param  list<array{label: string, model: Model, sanitizer: ?Sanitizer}>  $targets
     * @param  array<int, array<string, mixed>>  $fieldsByTarget
     * @param  array<string, list<int>>  $declared
     * @param  array<string, class-string<Sanitizer>>  $listedBy
     */
    private function assertKeyedGroup(array $members, array $targets, array $fieldsByTarget, array $declared, array $listedBy): void
    {
        $firstNode = null;
        $firstNamespace = null;

        foreach ($members as $node) {
            $index = $declared[$node][0];
            $column = $this->columnOf($node);
            $definition = $fieldsByTarget[$index][$column];

            if (! $definition instanceof Keyed) {
                throw InvalidConfigurationException::mirroredColumnNotKeyed($targets[$index]['model']::class, $column, $listedBy[$node]);
            }

            if ($firstNode === null) {
                $firstNode = $node;
                $firstNamespace = $definition->namespace();

                continue;
            }

            if ($definition->namespace() !== $firstNamespace) {
                throw InvalidConfigurationException::mirrorNamespaceMismatch($firstNode, $node, (string) $firstNamespace, $definition->namespace());
            }
        }
    }

    /**
     * @param  list<string>  $members
     * @param  list<array{label: string, model: Model, sanitizer: ?Sanitizer}>  $targets
     * @param  array<string, list<int>>  $declared
     * @param  array<string, Connection>  $suspend
     */
    private function assertForeignKeyClosure(array $members, array $targets, array $declared, array &$suspend): void
    {
        /** @var array<string, true> $canonicalMembers members keyed "{table key}.{column key}" */
        $canonicalMembers = [];

        foreach ($members as $node) {
            $model = $targets[$declared[$node][0]]['model'];
            $connection = $this->db->connection($model->getConnectionName());

            $canonicalMembers[$this->foreignKeys->tableKey($connection, $model->getTable()).'.'.$this->foreignKeys->columnKey($connection, $this->columnOf($node))] = true;
        }

        foreach ($members as $node) {
            $index = $declared[$node][0];
            $model = $targets[$index]['model'];
            $sanitizer = $targets[$index]['sanitizer'];
            $table = $model->getTable();
            $column = $this->columnOf($node);

            if ($sanitizer === null) {
                continue;
            }

            $connection = $this->db->connection($model->getConnectionName());
            $tableKey = $this->foreignKeys->tableKey($connection, $table);
            $columnKey = $this->foreignKeys->columnKey($connection, $column);

            foreach ($this->foreignKeys->edgesTouching($connection, $table, $column) as $edge) {
                $suspend[$connection->getName()] = $connection;

                // Columns pair by position; a pair the metadata leaves unmatched cannot be checked.
                if (count($edge['child_columns']) !== count($edge['parent_columns'])) {
                    throw UnsafeColumnException::malformedForeignKey($edge['child_table'], $edge['child_columns'], $edge['parent_table'], $edge['parent_columns']);
                }

                foreach ($edge['child_columns'] as $position => $childColumn) {
                    $parentColumn = $edge['parent_columns'][$position];

                    // A same-table edge can match on both sides; check both.
                    // Column names are compared by key: SQLite and MySQL
                    // accept a foreign key naming a column in another case.
                    if ($edge['child_key'] === $tableKey && $this->foreignKeys->columnKey($connection, $childColumn) === $columnKey) {
                        $this->assertEndpoint($edge['parent_key'], $this->foreignKeys->columnKey($connection, $parentColumn), $canonicalMembers, $model, $column, $sanitizer, $table, $edge['child_table'], $childColumn, $edge['parent_table'], $parentColumn);
                    }

                    if ($edge['parent_key'] === $tableKey && $this->foreignKeys->columnKey($connection, $parentColumn) === $columnKey) {
                        $this->assertEndpoint($edge['child_key'], $this->foreignKeys->columnKey($connection, $childColumn), $canonicalMembers, $model, $column, $sanitizer, $table, $edge['child_table'], $childColumn, $edge['parent_table'], $parentColumn);
                    }
                }
            }
        }
    }

    /**
     * @param  string  $otherTable  the other endpoint's table key (ForeignKeyInspector::tableKey())
     * @param  string  $otherColumn  the other endpoint's column key (ForeignKeyInspector::columnKey())
     * @param  array<string, true>  $canonicalMembers  members keyed "{table key}.{column key}"
     */
    private function assertEndpoint(string $otherTable, string $otherColumn, array $canonicalMembers, Model $model, string $column, Sanitizer $sanitizer, string $table, string $childTable, string $childColumn, string $parentTable, string $parentColumn): void
    {
        if (isset($canonicalMembers[$otherTable.'.'.$otherColumn])) {
            return;
        }

        throw UnsafeColumnException::undeclaredReference($model::class, $column, $sanitizer::class, $table, [
            'child_table' => $childTable,
            'child_column' => $childColumn,
            'parent_table' => $parentTable,
            'parent_column' => $parentColumn,
        ]);
    }

    private function columnOf(string $node): string
    {
        return substr($node, (int) strrpos($node, '.') + 1);
    }

    /**
     * @param  array<string, string>  $parent
     * @param  list<string>  $nodes
     */
    private function touch(string $node, array &$parent, array &$nodes): void
    {
        if (! isset($parent[$node])) {
            $parent[$node] = $node;
            $nodes[] = $node;
        }
    }

    /**
     * @param  array<string, string>  $parent
     */
    private function find(string $node, array &$parent): string
    {
        while ($parent[$node] !== $node) {
            $parent[$node] = $parent[$parent[$node]];
            $node = $parent[$node];
        }

        return $node;
    }

    /**
     * @param  array<string, string>  $parent
     */
    private function union(string $a, string $b, array &$parent): void
    {
        $rootA = $this->find($a, $parent);
        $rootB = $this->find($b, $parent);

        if ($rootA !== $rootB) {
            $parent[$rootB] = $rootA;
        }
    }
}
