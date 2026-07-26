<?php

/**
 * Task relation semantics for the MCP layer.
 *
 * pm stores a relation as a **single** `pm_task_dependency` row
 * (task_id, depends_on_task_id, type) and reads it from both ends:
 * the row "A depends on B" appears on A's card under `depends_on` and on B's
 * card under `blocks`; the symmetric types (DUPLICATES, RELATES_TO) surface
 * under `related` whichever side you look from. So a relation is mutual by
 * construction — writing a second, mirrored row would only duplicate it in both
 * cards.
 *
 * What an agent needs is a way to say which end it is speaking from. This
 * helper maps the caller's point of view — "task X blocks Y", "X depends on Y",
 * "X duplicates Y", "X relates to Y" — onto the one row that stores it, and
 * back: given a stored row and a task, it names the relation as that task sees
 * it and the inverse the counterpart sees.
 */
class pmMcpDependencyHelper
{
    /** Directional relations, named from the point of view of the calling task. */
    const RELATION_DEPENDS_ON = 'depends_on';
    const RELATION_BLOCKS     = 'blocks';

    /** Symmetric relations: both ends read them the same way. */
    const RELATION_DUPLICATES = 'duplicates';
    const RELATION_RELATES_TO = 'relates_to';

    /** Scheduling types a directional relation may carry (pm's enum). */
    const DIRECTED_TYPES = array('FS', 'SS', 'FF', 'SF');

    /** The `type` value each symmetric relation is stored as. */
    const SYMMETRIC_TYPES = array(
        self::RELATION_DUPLICATES => 'DUPLICATES',
        self::RELATION_RELATES_TO => 'RELATES_TO',
    );

    /**
     * Every relation an agent may ask for.
     *
     * @return string[]
     */
    public static function relations()
    {
        return array(
            self::RELATION_DEPENDS_ON,
            self::RELATION_BLOCKS,
            self::RELATION_DUPLICATES,
            self::RELATION_RELATES_TO,
        );
    }

    public static function isSymmetric($relation)
    {
        return isset(self::SYMMETRIC_TYPES[$relation]);
    }

    /**
     * The relation the counterpart task sees for the same row: the inverse of a
     * directional relation, and itself for a symmetric one.
     *
     * @param string $relation
     * @return string
     */
    public static function inverse($relation)
    {
        if ($relation === self::RELATION_DEPENDS_ON) {
            return self::RELATION_BLOCKS;
        }
        if ($relation === self::RELATION_BLOCKS) {
            return self::RELATION_DEPENDS_ON;
        }
        return $relation;
    }

    /**
     * The single row that stores a relation, given the caller's point of view.
     *
     * "A depends on B" and "A relates to B" are stored on A; "A blocks B" is
     * stored on B, because pm's row always means "task_id depends on
     * depends_on_task_id". That inversion is exactly what makes the relation
     * show up as `blocks` on A's card and `depends_on` on B's.
     *
     * @param string $relation  One of relations().
     * @param int    $task_id       The task the caller is speaking from (A).
     * @param int    $other_task_id The counterpart (B).
     * @param string $type          Scheduling type for a directional relation.
     * @return array{task_id: int, depends_on_task_id: int, type: string}
     */
    public static function rowFor($relation, $task_id, $other_task_id, $type = 'FS')
    {
        $task_id = (int) $task_id;
        $other_task_id = (int) $other_task_id;

        if (self::isSymmetric($relation)) {
            return array(
                'task_id'            => $task_id,
                'depends_on_task_id' => $other_task_id,
                'type'               => self::SYMMETRIC_TYPES[$relation],
            );
        }

        $type = self::normalizeType($type);

        return $relation === self::RELATION_BLOCKS
            ? array('task_id' => $other_task_id, 'depends_on_task_id' => $task_id, 'type' => $type)
            : array('task_id' => $task_id, 'depends_on_task_id' => $other_task_id, 'type' => $type);
    }

    /**
     * Normalise a directional type to pm's enum spelling, defaulting to FS.
     *
     * @throws waAPIException invalid_param on anything else.
     */
    public static function normalizeType($type)
    {
        $normalized = strtoupper(trim((string) $type));
        if ($normalized === '') {
            return 'FS';
        }
        if (!in_array($normalized, self::DIRECTED_TYPES, true)) {
            throw new waAPIException(
                'invalid_param',
                sprintf(_wp('Unknown dependency type "%s". Use one of: FS, SS, FF, SF.'), $type),
                400
            );
        }
        return $normalized;
    }

    /**
     * Name a stored row as the given task sees it.
     *
     * @param array $row      A pm_task_dependency row.
     * @param int   $task_id  The side we are reading from.
     * @return string  One of relations().
     */
    public static function relationFromRow(array $row, $task_id)
    {
        $type = strtoupper((string) ($row['type'] ?? 'FS'));
        if ($type === 'DUPLICATES') {
            return self::RELATION_DUPLICATES;
        }
        if ($type === 'RELATES_TO') {
            return self::RELATION_RELATES_TO;
        }
        return (int) $row['task_id'] === (int) $task_id
            ? self::RELATION_DEPENDS_ON
            : self::RELATION_BLOCKS;
    }

    /**
     * The counterpart task id in a stored row.
     */
    public static function counterpart(array $row, $task_id)
    {
        return (int) $row['task_id'] === (int) $task_id
            ? (int) $row['depends_on_task_id']
            : (int) $row['task_id'];
    }

    /**
     * Every relation stored between two tasks, in either direction.
     *
     * A relation occupies one slot per pair: the tasks are either dependent,
     * duplicates or merely related, never several of those at once. Callers use
     * this to answer "is the requested relation already there?" (idempotent add)
     * and "is a different one in the way?" (conflict).
     *
     * @return array  pm_task_dependency rows, oldest first.
     */
    public static function findBetween($task_id, $other_task_id)
    {
        $model = new pmTaskDependencyModel();
        $rows = array_merge(
            (array) $model->getByField(array('task_id' => (int) $task_id, 'depends_on_task_id' => (int) $other_task_id), true),
            (array) $model->getByField(array('task_id' => (int) $other_task_id, 'depends_on_task_id' => (int) $task_id), true)
        );
        usort($rows, function ($a, $b) {
            return (int) $a['id'] - (int) $b['id'];
        });
        return $rows;
    }

    /**
     * Describe a stored row for a tool response: both points of view plus the
     * raw row, so an agent can see that one record serves both tasks.
     *
     * @param array $row      A pm_task_dependency row.
     * @param int   $task_id  The task the caller spoke from.
     * @return array
     */
    public static function describe(array $row, $task_id)
    {
        $task_id = (int) $task_id;
        $other_task_id = self::counterpart($row, $task_id);
        $relation = self::relationFromRow($row, $task_id);

        return array(
            'dependency_id'    => (int) $row['id'],
            'task_id'          => $task_id,
            'task_full_number' => self::fullNumber($task_id),
            'relation'         => $relation,
            'type'             => (string) $row['type'],
            'related_task_id'  => $other_task_id,
            'related_full_number' => self::fullNumber($other_task_id),
            // How the counterpart's card reads the very same row.
            'inverse_relation' => self::inverse($relation),
            'mutual'           => true,
            'stored_as'        => array(
                'task_id'            => (int) $row['task_id'],
                'depends_on_task_id' => (int) $row['depends_on_task_id'],
                'type'               => (string) $row['type'],
            ),
        );
    }

    /**
     * A task's full number ("AUTH-32"), or "#32" when the task is gone.
     */
    protected static function fullNumber($task_id)
    {
        $task = (new pmTaskModel())->getById((int) $task_id);
        return $task
            ? pmMcpTaskHelper::formatNumber((int) $task['id'], (int) $task['project_id'])
            : '#' . (int) $task_id;
    }

    /**
     * Annotate pmTaskDependencyModel::getByTask() output for MCP consumers.
     *
     * The model's shape is built for the app's own card, where the counterpart
     * is a different key in each section (`depends_on_task_id`, `task_id`,
     * `related_task_id`) and a task is identified by a bare id. An agent needs
     * one predictable key and a quotable number, so every entry also gets
     * `related_task_id`, `related_full_number`, `related_project_id`,
     * `relation` and `inverse_relation`.
     *
     * @param array $dependencies  {depends_on: [], blocks: [], related: []}
     * @param int   $task_id       The task the sections were read for.
     * @return array               The same structure, entries annotated.
     */
    public static function annotate(array $dependencies, $task_id)
    {
        $task_id = (int) $task_id;

        // Counterpart ids across all three sections, for one project lookup.
        $ids = array();
        foreach ($dependencies as $section) {
            foreach ((array) $section as $entry) {
                $ids[] = self::counterpart($entry, $task_id);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        $projects = array();
        if ($ids) {
            $rows = (new pmTaskModel())->select('id, project_id')->where('id IN (i:ids)', array('ids' => $ids))->fetchAll('id');
            foreach ($rows as $id => $row) {
                $projects[(int) $id] = (int) $row['project_id'];
            }
        }

        foreach ($dependencies as $key => $section) {
            foreach ((array) $section as $i => $entry) {
                $other = self::counterpart($entry, $task_id);
                $relation = self::relationFromRow($entry, $task_id);
                $project_id = $projects[$other] ?? 0;

                $entry['related_task_id'] = $other;
                $entry['related_project_id'] = $project_id;
                $entry['related_full_number'] = $project_id
                    ? pmMcpTaskHelper::formatNumber($other, $project_id)
                    : '#' . $other;
                $entry['relation'] = $relation;
                $entry['inverse_relation'] = self::inverse($relation);

                $dependencies[$key][$i] = $entry;
            }
        }

        return $dependencies;
    }

    /**
     * A task's annotated relations, as returned by pm_get_task and the write
     * tool.
     */
    public static function forTask($task_id)
    {
        $task_id = (int) $task_id;
        return self::annotate((new pmTaskDependencyModel())->getByTask($task_id), $task_id);
    }
}
