<?php

namespace Phormium;

use Phormium\Database\Driver;
use Phormium\Filter\Filter;
use Phormium\Query\QuerySegment;

use PDO;

/**
 * Generates and executes SQL queries.
 */
class Query
{
    /**
     * Meta data of the model which the Query will handle.
     * @var \Phormium\Meta
     */
    private $meta;

    public function __construct(Meta $meta)
    {
        $this->meta = $meta;
    }

    /**
     * Constructs and executes a SELECT query based on the given parameters.
     * Returns an array of data fetched from the database.
     *
     * @param Filter $filter A filter instance used to form the WHERE clause.
     * @param array $order Array of [<column>, <direction>] pairs used to form
     *      the ORDER BY clause.
     * @param array $columns Array of columns to fetch, or NULL for all columns.
     * @param integer $limit The max. number of rows to fetch.
     * @param integer $offset The number of rows to skip from beginning.
     * @param integer $fetchType Fetch type; one of PDO::FETCH_* constants.
     *
     * @return array An array of {@link Model} instances when using
     *      PDO::FETCH_CLASS, an array of associative arrays when using
     *      PDO::FETCH_ASSOC.
     */
    public function select(
        Filter $filter = null,
        array $order = null,
        array $columns = null,
        $limit = null,
        $offset = null,
        $fetchType = PDO::FETCH_CLASS
    ) {
        if (isset($columns)) {
            $this->checkColumnsExist($columns);
        } else {
            $columns = $this->meta->getColumns();
        }

        $table = $this->meta->getTable();
        $class = $this->meta->getClass();

        $segment = $this->queryBuilder()->buildSelect($table, $columns, $filter, $limit, $offset, $order);

        return $this->connection()->preparedQuery($segment, $fetchType, $class);
    }

    /**
     * Constructs and executes a SELECT DISTINCT query.
     *
     * @param Filter $filter A filter instance used to form the WHERE clause.
     * @param array $order Array of strings used to form the ORDER BY clause.
     * @param integer $limit The max. number of rows to fetch.
     * @param integer $offset The number of rows to skip from beginning.
     *
     * @return array An array distinct values. If multiple columns are given,
     *      will return an array of arrays, and each of these will have
     *      the distinct values indexed by column name. If a single column is
     *      given will return an array of distinct values for that column.
     */
    public function selectDistinct(
        Filter $filter = null,
        array $order = null,
        array $columns = null,
        $limit = null,
        $offset = null
    ) {
        $table = $this->meta->getTable();

        if (empty($columns)) {
            throw new \Exception("No columns given");
        }

        $this->checkColumnsExist($columns);

        $segment = $this->queryBuilder()->buildSelect($table, $columns, $filter, $limit, $offset, $order, true);

        if (count($columns) > 1) {
            // If multiple columns, return array of arrays
            return $this->connection()->preparedQuery($segment);
        } else {
            // If single column, return array of strings
            return $this->connection()->singleColumnPreparedQuery($segment, reset($columns));
        }
    }

    /**
     * Constructs and executes a SELECT aggregate query.
     *
     * @param  Filter    $filter     A filter instance used to form the WHERE clause.
     * @param  Aggregate $aggregate  The aggregate to perform.
     * @return string                Result of the aggregate query.
     */
    public function aggregate(Aggregate $aggregate, Filter $filter = null)
    {
        $table = $this->meta->getTable();
        $column = $aggregate->column;
        $type = $aggregate->type;

        if (!$this->meta->columnExists($column)) {
            if (!($type === Aggregate::COUNT && $column === '*')) {
                throw new \Exception(
                    "Error forming aggregate query. " .
                    "Column [$column] does not exist in table [$table]."
                );
            }
        }

        $segment = $this->queryBuilder()->buildSelectAggregate($table, $aggregate, $filter);

        $data = $this->connection()->preparedQuery($segment);

        return array_shift($data[0]);
    }

    /**
     * Constructs and executes an INSERT statement for a single Model instance.
     */
    public function insert(Model $model)
    {
        $table = $this->meta->getTable();
        $pkColumns = $this->meta->getPkColumns();

        // Determine whether to allow PK to be generated by the database. Conditions:
        // 1. Only single-column primary keys
        // 2. The pk column must not be populated
        $pkAutogen = false;
        if (count($pkColumns) === 1) {
            $pkColumn = $pkColumns[0];
            if (!isset($model->{$pkColumn})) {
                $pkAutogen = true;
            }
        }

        // If PK is auto-generated, exclude it from the insert query
        $columns = $pkAutogen ?
            $this->meta->getNonPkColumns() :
            $this->meta->getColumns();

        // If PK is not auto-generated, make sure all PK columns are populated
        if (!$pkAutogen) {
            foreach ($pkColumns as $column) {
                if (!isset($model->{$column})) {
                    throw new \Exception("Cannot insert. Primary key column(s) not set.");
                }
            }
        }

        // Collect query arguments
        $values = [];
        foreach ($columns as $column) {
            $values[] = $model->{$column};
        }

        // PostgreSQL needs a RETURNING clause to get the inserted ID because
        // it does not support PDO->lastInsertId().
        $driver = $this->connection()->getDriver();
        $returning = ($driver == Driver::PGSQL && $pkAutogen) ? $pkColumn : null;

        $segment = $this->queryBuilder()->buildInsert($table, $columns, $values, $returning);

        // If primary key is generated by the database, populate it
        if ($pkAutogen) {
            // For Postgres, do fetch to retrieve the generated primary key via
            // the RETURNING clause. For others use PDO->lastInsertId().
            if ($driver == 'pgsql') {
                $data = $this->connection()->preparedQuery($segment);
                $id = $data[0][$pkColumn];
            } else {
                $this->connection()->preparedExecute($segment);
                $id = $this->connection()->getPDO()->lastInsertId();
            }
            $model->{$pkColumn} = $id;
        } else {
            $this->connection()->preparedExecute($segment);
        }
    }

    /**
     * Constructs and executes an UPDATE statement for a single Model instance.
     */
    public function update(Model $model)
    {
        $table = $this->meta->getTable();
        $pkColumns = $this->meta->getPkColumns();
        $columns = $this->meta->getNonPkColumns();
        $filter = $model::getPkFilter($model->getPK());

        if (empty($pkColumns)) {
            throw new \Exception("Cannot update. Model does not have a primary key defined in _meta.");
        }

        // All pk fields must be set to attempt an update
        foreach ($pkColumns as $column) {
            if (!isset($model->{$column})) {
                throw new \Exception("Cannot update. Primary key column [$column] is not set.");
            }
        }

        // Values to update
        $values = array_map(function ($column) use ($model) {
            return $model->{$column};
        }, $columns);

        $segment = $this->queryBuilder()->buildUpdate($table, $columns, $values, $filter);

        return $this->connection()->preparedExecute($segment);
    }

    /**
     * Deletes a single model from the database.
     */
    public function delete(Model $model)
    {
        $pk = $model->getPK();
        $table = $model->getMeta()->getTable();
        $filter = $model::getPkFilter($pk);

        $segment = $this->queryBuilder()->buildDelete($table, $filter);

        return $this->connection()->preparedExecute($segment);
    }

    /**
     * Constructs and executes an UPDATE statement for all records matching
     * the given filters.
     */
    public function batchUpdate(array $updates, Filter $filter = null)
    {
        $table = $this->meta->getTable();

        $updateColumns = array_keys($updates);
        $updateValues = array_values($updates);

        $this->checkColumnsExist($updateColumns);

        $segment = $this->queryBuilder()->buildUpdate($table, $updateColumns, $updateValues, $filter);

        return $this->connection()->preparedExecute($segment);
    }

    /**
     * Constructs and executes a DELETE statement for all records matching
     * the given filters.
     */
    public function batchDelete(Filter $filter = null)
    {
        $table = $this->meta->getTable();

        $segment = $this->queryBuilder()->buildDelete($table, $filter);

        return $this->connection()->preparedExecute($segment);
    }

    // ******************************************
    // *** Private methods                    ***
    // ******************************************

    /**
     * Checks that each of the columns in $columns exists in the uderlying
     * model.
     */
    private function checkColumnsExist(array $columns)
    {
        $columns = $this->meta->getColumns();

        foreach ($columns as $column) {
            if (!$this->meta->columnExists($column)) {
                $table = $this->meta->getTable();
                throw new \Exception("Column [$column] does not exist in table [$table].");
            }
        }
    }

    private function connection()
    {
        $database = $this->meta->getDatabase();

        return Orm::database()->getConnection($database);
    }

    private function queryBuilder()
    {
        $database = $this->meta->getDatabase();
        $conn = Orm::database()->getConnection($database);
        $driver = $conn->getDriver();

        return Orm::queryBuilder($driver);
    }
}
