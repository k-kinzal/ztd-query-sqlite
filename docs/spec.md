# SQLite SQL Specification for ZTD

This document defines how ZTD (Zero Table Dependency) handles SQLite SQL statements. ZTD simulates query results without modifying the physical database by using CTEs to shadow tables and virtualize writes.

**Grammar Reference:** SQLite 3.47.2 official Lemon grammar (`parse.y`). The `cmd` rule defines 40 production alternatives. This spec covers all 40 alternatives. Additionally, the `explain` rule wraps `cmd` to add `EXPLAIN` and `EXPLAIN QUERY PLAN` prefixes.

## Overview

ZTD categorizes SQL statements into three handling modes:

| Mode | Description |
|------|-------------|
| **Rewrite** | Transform the query using CTE shadowing to simulate the operation |
| **Ignored** | Statement has no effect (by design) |
| **Unsupported** | Follows Unsupported SQL behavior (ignore/notice/exception/passthrough) |

> **Note:** SQLite does not have a separate TCL "Ignored" category in the query guard. Transaction statements are classified as unsupported (null from the classifier), not as SKIPPED.

---

## DML (Data Manipulation Language)

Grammar rules: `select` (via `cmd`), `INSERT`/`REPLACE` (via `insert_cmd`), `UPDATE`, `DELETE`

### SELECT (cmd -> select)

| Mode | Behavior |
|------|----------|
| Rewrite | Apply CTE shadowing to inject virtual mutations from ShadowStore |

**Grammar:** `cmd` alternative #11: `select`. The `select` rule expands to `selectnowith` or `with selectnowith`. The `oneselect` rule has 4 alternatives: basic SELECT, VALUES clause, and set operations via `multiselect_op` (UNION, UNION ALL, EXCEPT, INTERSECT).

**Supported Syntax:**
- `SELECT ... FROM ...` - Basic select
- `SELECT ... WHERE ...` - Conditional select
- `SELECT ... JOIN ...` - Join operations (INNER, LEFT, CROSS, NATURAL)
- `SELECT ... GROUP BY ...` - Aggregation
- `SELECT ... HAVING ...` - Aggregate filtering
- `SELECT ... ORDER BY ...` - Sorting
- `SELECT ... LIMIT ... OFFSET ...` - Pagination
- `SELECT ... UNION [ALL] ...` - Set union
- `SELECT ... EXCEPT ...` - Set difference
- `SELECT ... INTERSECT ...` - Set intersection
- `SELECT ... (subquery)` - Subqueries
- `WITH ... SELECT ...` - Common Table Expressions
- `WITH RECURSIVE ... SELECT ...` - Recursive CTEs
- `SELECT ... WINDOW ...` - Window function definitions (SQLite 3.25+)
- `SELECT DISTINCT ...` - Distinct filtering
- `VALUES (...)` - Values expression (standalone)

**Unsupported Syntax:**
- `SELECT ... INTO ...` - Not supported by SQLite

### INSERT (cmd -> with insert_cmd INTO ...)

| Mode | Behavior |
|------|----------|
| Rewrite | Convert to result-select query, store mutation in ShadowStore |

**Grammar:** `cmd` alternatives #16 and #17. Alternative #16: `with insert_cmd INTO xfullname idlist_opt select upsert` (INSERT with values/select). Alternative #17: `with insert_cmd INTO xfullname idlist_opt DEFAULT VALUES returning` (INSERT DEFAULT VALUES). The `insert_cmd` rule has 2 alternatives: `INSERT orconf` and `REPLACE`.

**Transformation:**
```sql
-- Original
INSERT INTO users (id, name) VALUES (1, 'Alice')

-- Transformed (Result Select)
SELECT 1 AS "id", 'Alice' AS "name"
```

**Supported Syntax:**
- `INSERT INTO ... VALUES (...)` - Single row insert
- `INSERT INTO ... VALUES (...), (...)` - Multi-row insert
- `INSERT ... SELECT ...` - Insert from subquery
- `INSERT ... ON CONFLICT ... DO UPDATE SET ...` - Upsert
- `INSERT ... ON CONFLICT ... DO NOTHING` - Skip conflicts
- `INSERT OR REPLACE INTO ...` - Replace on conflict
- `INSERT OR IGNORE INTO ...` - Ignore on conflict
- `INSERT OR ROLLBACK INTO ...` - Rollback on conflict
- `INSERT OR ABORT INTO ...` - Abort on conflict
- `INSERT OR FAIL INTO ...` - Fail on conflict
- `REPLACE INTO ...` - Replace (synonym for INSERT OR REPLACE)
- `WITH ... INSERT ...` - CTE with INSERT
- `INSERT INTO ... DEFAULT VALUES` - Insert defaults only

**SQLite-Specific Conflict Handling (from `orconf` rule):**
```sql
-- INSERT OR REPLACE
INSERT OR REPLACE INTO users (id, name) VALUES (1, 'Bob')
-- Resolved as ReplaceMutation

-- REPLACE INTO (synonym)
REPLACE INTO users (id, name) VALUES (1, 'Bob')
-- Resolved as ReplaceMutation

-- INSERT OR IGNORE
INSERT OR IGNORE INTO users (id, name) VALUES (1, 'Bob')
-- Resolved as InsertMutation with isIgnore=true

-- ON CONFLICT ... DO UPDATE (from `upsert` rule, 6 alternatives)
INSERT INTO users (id, name) VALUES (1, 'Bob')
ON CONFLICT (id) DO UPDATE SET name = excluded.name
-- Resolved as UpsertMutation
```

**Unsupported Syntax:**
- `INSERT ... RETURNING ...` - RETURNING clause (SQLite 3.35+); Result Select approach is used instead

### UPDATE (cmd -> with UPDATE ...)

| Mode | Behavior |
|------|----------|
| Rewrite | Convert to result-select query, store mutation in ShadowStore |

**Grammar:** `cmd` alternatives #14 and #15. Alternative #14: `with UPDATE orconf xfullname indexed_opt SET setlist from where_opt_ret orderby_opt limit_opt` (with ORDER BY/LIMIT). Alternative #15: `with UPDATE orconf xfullname indexed_opt SET setlist from where_opt_ret` (simple).

**Transformation:**
```sql
-- Original
UPDATE users SET name = 'Bob' WHERE id = 1

-- Transformed (Result Select with CTE shadowing)
WITH "users" AS (
  SELECT CAST(1 AS INTEGER) AS "id", CAST('Alice' AS TEXT) AS "name"
)
SELECT 'Bob' AS "name", "users"."id" FROM "users" WHERE id = 1
```

**Supported Syntax:**
- `UPDATE ... SET ... WHERE ...` - Single table update
- `UPDATE ... SET ... ORDER BY ... LIMIT ...` - Limited update
- `UPDATE OR ROLLBACK/ABORT/REPLACE/FAIL/IGNORE ... SET ...` - Conflict resolution (from `orconf` rule)
- `WITH ... UPDATE ...` - CTE with UPDATE
- `UPDATE ... SET ... FROM ...` - Multi-table update (SQLite 3.33+, from `from` rule)

**Unsupported Syntax:**
- `UPDATE ... RETURNING ...` - RETURNING clause (SQLite 3.35+); Result Select approach is used instead

### DELETE (cmd -> with DELETE FROM ...)

| Mode | Behavior |
|------|----------|
| Rewrite | Convert to result-select query, store mutation in ShadowStore |

**Grammar:** `cmd` alternatives #12 and #13. Alternative #12: `with DELETE FROM xfullname indexed_opt where_opt_ret orderby_opt limit_opt` (with ORDER BY/LIMIT). Alternative #13: `with DELETE FROM xfullname indexed_opt where_opt_ret` (simple).

**Transformation:**
```sql
-- Original
DELETE FROM users WHERE id = 1

-- Transformed (Result Select with CTE shadowing)
WITH "users" AS (
  SELECT CAST(1 AS INTEGER) AS "id", CAST('Alice' AS TEXT) AS "name"
)
SELECT "users"."id" AS "id", "users"."name" AS "name" FROM "users" WHERE id = 1
```

**Special Case - DELETE without WHERE (Truncate equivalent):**
```sql
-- Original
DELETE FROM users

-- No transformation needed (acts as TRUNCATE)
-- Returns: SELECT 1 WHERE 0
-- ShadowStore: DeleteMutation clears the table
```

**Supported Syntax:**
- `DELETE FROM ... WHERE ...` - Conditional delete
- `DELETE FROM ... ORDER BY ... LIMIT ...` - Limited delete
- `DELETE FROM ...` - Delete all rows (treated as truncation)
- `WITH ... DELETE ...` - CTE with DELETE

**Unsupported Syntax:**
- `DELETE ... RETURNING ...` - RETURNING clause; Result Select approach is used instead

### TRUNCATE

SQLite does not have a `TRUNCATE TABLE` statement. Use `DELETE FROM table` (without WHERE clause) instead, which ZTD handles as a truncation operation.

---

## DDL (Data Definition Language)

ZTD manages DDL virtually within SchemaRegistry. Physical database schema is never modified.

Grammar rules: SQLite has 14 DDL-related `cmd` alternatives covering tables, indexes, views, triggers, and virtual tables. Only table-related DDL is rewritten; all other DDL is unsupported.

### CREATE TABLE (cmd -> create_table create_table_args)

| Mode | Behavior |
|------|----------|
| Rewrite | Register virtual table in SchemaRegistry |

**Grammar:** `cmd` alternative #7: `create_table create_table_args`. The `create_table` rule: `createkw temp TABLE ifnotexists nm dbnm`. The `create_table_args` rule has 2 alternatives: column definitions `LP columnlist conslist_opt RP table_option_set` and `AS select` (CREATE TABLE AS SELECT).

**Supported Syntax:**
- `CREATE TABLE ...` - Create virtual table
- `CREATE TABLE IF NOT EXISTS ...` - Conditional create
- `CREATE TEMPORARY TABLE ...` - Temporary table (treated as normal)
- `CREATE TABLE ... WITHOUT ROWID` - WITHOUT ROWID table (recognized in parsing, from `table_option` rule)

**Unsupported Syntax:**
- `CREATE TABLE ... AS SELECT ...` - Not handled (grammar supports it via `create_table_args`, but mutation resolver does not implement it)

### ALTER TABLE (cmd -> ALTER TABLE ...)

| Mode | Behavior |
|------|----------|
| Rewrite | Modify virtual table structure in SchemaRegistry |

**Grammar:** `cmd` alternatives #35-#38 define 4 ALTER TABLE operations:
- #35: `ALTER TABLE fullname RENAME TO nm` - Rename table
- #36: `ALTER TABLE add_column_fullname ADD kwcolumn_opt columnname carglist` - Add column
- #37: `ALTER TABLE fullname DROP kwcolumn_opt nm` - Drop column (SQLite 3.35+)
- #38: `ALTER TABLE fullname RENAME kwcolumn_opt nm TO nm` - Rename column (SQLite 3.25+)

**Supported Operations:**
- `ALTER TABLE ... ADD COLUMN ...` - Add virtual column (alternative #36)
- `ALTER TABLE ... ADD ...` - Add column (COLUMN keyword optional, from `kwcolumn_opt` rule)
- `ALTER TABLE ... DROP COLUMN ...` - Drop virtual column (alternative #37, SQLite 3.35+)
- `ALTER TABLE ... DROP ...` - Drop column (COLUMN keyword optional)
- `ALTER TABLE ... RENAME TO ...` - Rename virtual table (alternative #35)
- `ALTER TABLE ... RENAME COLUMN ... TO ...` - Rename column (alternative #38, SQLite 3.25+)
- `ALTER TABLE ... RENAME ... TO ...` - Rename column (without COLUMN keyword)

**Design Decision:** SQLite's ALTER TABLE is intentionally limited compared to MySQL/PostgreSQL. The grammar defines exactly 4 operations and ZTD supports all of them. Operations not listed above throw `UnsupportedSqlException`.

### DROP TABLE (cmd -> DROP TABLE ...)

| Mode | Behavior |
|------|----------|
| Rewrite | Remove virtual table from SchemaRegistry |

**Grammar:** `cmd` alternative #8: `DROP TABLE ifexists fullname`.

**Supported Syntax:**
- `DROP TABLE ...`
- `DROP TABLE IF EXISTS ...`

### Other DDL (Unsupported)

All non-table DDL follows Unsupported SQL behavior. The complete list from the grammar:

| # | Grammar Production | SQL Statement | Category |
|---|-------------------|---------------|----------|
| **Index** | | | |
| #18 | `createkw uniqueflag INDEX ifnotexists nm dbnm ON nm LP sortlist RP where_opt` | `CREATE [UNIQUE] INDEX ...` | Index |
| #19 | `DROP INDEX ifexists fullname` | `DROP INDEX ...` | Index |
| **View** | | | |
| #9 | `createkw temp VIEW ifnotexists nm dbnm eidlist_opt AS select` | `CREATE [TEMP] VIEW ...` | View |
| #10 | `DROP VIEW ifexists fullname` | `DROP VIEW ...` | View |
| **Trigger** | | | |
| #27 | `createkw trigger_decl BEGIN trigger_cmd_list END` | `CREATE TRIGGER ...` | Trigger |
| #28 | `DROP TRIGGER ifexists fullname` | `DROP TRIGGER ...` | Trigger |
| **Virtual Table** | | | |
| #39 | `create_vtab` | `CREATE VIRTUAL TABLE ...` | Virtual Table |
| #40 | `create_vtab LP vtabarglist RP` | `CREATE VIRTUAL TABLE ... USING module(args)` | Virtual Table |

---

## TCL (Transaction Control Language)

Grammar rules: `cmd` alternatives #1-#6

| Mode | Behavior |
|------|----------|
| Unsupported | Transaction statements are not classified by the query guard |

**Design Decision:** Unlike MySQL and PostgreSQL where TCL is explicitly classified as SKIPPED, SQLite's query guard does not recognize TCL statements. They are treated as unsupported and follow the configured Unsupported SQL behavior.

**Grammar alternatives (6 total):**
- #1: `BEGIN transtype trans_opt` - Begin transaction (from `transtype` rule: DEFERRED, IMMEDIATE, EXCLUSIVE, or empty)
- #2: `COMMIT trans_opt` - Commit transaction (also END)
- #3: `ROLLBACK trans_opt` - Rollback transaction
- #4: `SAVEPOINT nm` - Create savepoint
- #5: `RELEASE savepoint_opt nm` - Release savepoint
- #6: `ROLLBACK trans_opt TO savepoint_opt nm` - Rollback to savepoint

**Unrecognized Statements:**
- `BEGIN` / `BEGIN TRANSACTION` / `BEGIN DEFERRED/IMMEDIATE/EXCLUSIVE`
- `COMMIT` / `END` / `END TRANSACTION`
- `ROLLBACK` / `ROLLBACK TRANSACTION`
- `SAVEPOINT ...` / `RELEASE SAVEPOINT ...` / `ROLLBACK TO SAVEPOINT ...`

---

## DCL (Data Control Language)

SQLite does not have DCL statements. There is no GRANT, REVOKE, or user management in SQLite.

---

## Utility Statements

### PRAGMA (cmd alternatives #22-#26)

| Mode | Behavior |
|------|----------|
| Unsupported | Follows Unsupported SQL behavior (ignore/notice/exception/passthrough) |

**Grammar:** 5 `cmd` alternatives for PRAGMA:
- #22: `PRAGMA nm dbnm` - Query pragma (e.g., `PRAGMA table_info`)
- #23: `PRAGMA nm dbnm EQ nmnum` - Set pragma with `=` (e.g., `PRAGMA journal_mode = WAL`)
- #24: `PRAGMA nm dbnm LP nmnum RP` - Set pragma with `()` (e.g., `PRAGMA journal_mode(WAL)`)
- #25: `PRAGMA nm dbnm EQ minus_num` - Set pragma with negative value
- #26: `PRAGMA nm dbnm LP minus_num RP` - Set pragma with negative value in parentheses

> **Note:** Schema reflection is performed via `sqlite_master` and PRAGMA queries internally by `SqliteSchemaReflector`, not through user-facing SQL.

### EXPLAIN (explain rule)

| Mode | Behavior |
|------|----------|
| Unsupported | Follows Unsupported SQL behavior |

**Grammar:** The `explain` rule wraps `cmd` with 2 alternatives:
- `EXPLAIN` - Explain query plan
- `EXPLAIN QUERY PLAN` - Detailed query plan

### Maintenance

| # | Grammar Production | SQL Statement | Mode | Notes |
|---|-------------------|---------------|------|-------|
| #20 | `VACUUM vinto` | `VACUUM` | Unsupported | Database compaction |
| #21 | `VACUUM nm vinto` | `VACUUM schema_name` | Unsupported | Named database compaction |
| #31 | `REINDEX` | `REINDEX` | Unsupported | Rebuild all indexes |
| #32 | `REINDEX nm dbnm` | `REINDEX table_or_index` | Unsupported | Rebuild specific index |
| #33 | `ANALYZE` | `ANALYZE` | Unsupported | Collect statistics for all |
| #34 | `ANALYZE nm dbnm` | `ANALYZE table_or_index` | Unsupported | Collect statistics for specific table |

### Database Attachment

| # | Grammar Production | SQL Statement | Mode | Notes |
|---|-------------------|---------------|------|-------|
| #29 | `ATTACH database_kw_opt expr AS expr key_opt` | `ATTACH DATABASE file AS name` | Unsupported | Attach external database |
| #30 | `DETACH database_kw_opt expr` | `DETACH DATABASE name` | Unsupported | Detach external database |

---

## Complete Grammar Coverage

The following table maps all 40 `cmd` alternatives to their ZTD handling mode:

| # | Grammar Production | SQL Statement | Mode | Category |
|---|-------------------|---------------|------|----------|
| 1 | `BEGIN transtype trans_opt` | `BEGIN [DEFERRED\|IMMEDIATE\|EXCLUSIVE] [TRANSACTION]` | Unsupported | TCL |
| 2 | `COMMIT trans_opt` | `COMMIT [TRANSACTION]` / `END [TRANSACTION]` | Unsupported | TCL |
| 3 | `ROLLBACK trans_opt` | `ROLLBACK [TRANSACTION]` | Unsupported | TCL |
| 4 | `SAVEPOINT nm` | `SAVEPOINT name` | Unsupported | TCL |
| 5 | `RELEASE savepoint_opt nm` | `RELEASE [SAVEPOINT] name` | Unsupported | TCL |
| 6 | `ROLLBACK trans_opt TO savepoint_opt nm` | `ROLLBACK [TRANSACTION] TO [SAVEPOINT] name` | Unsupported | TCL |
| 7 | `create_table create_table_args` | `CREATE [TEMP] TABLE ...` | **Rewrite** | DDL |
| 8 | `DROP TABLE ifexists fullname` | `DROP TABLE [IF EXISTS] name` | **Rewrite** | DDL |
| 9 | `createkw temp VIEW ...` | `CREATE [TEMP] VIEW ...` | Unsupported | DDL |
| 10 | `DROP VIEW ifexists fullname` | `DROP VIEW [IF EXISTS] name` | Unsupported | DDL |
| 11 | `select` | `SELECT ...` / `VALUES (...)` | **Rewrite** | DML |
| 12 | `with DELETE FROM ... orderby limit` | `[WITH ...] DELETE FROM ... ORDER BY ... LIMIT ...` | **Rewrite** | DML |
| 13 | `with DELETE FROM ...` | `[WITH ...] DELETE FROM ... [WHERE ...]` | **Rewrite** | DML |
| 14 | `with UPDATE orconf ... orderby limit` | `[WITH ...] UPDATE [OR ...] ... ORDER BY ... LIMIT ...` | **Rewrite** | DML |
| 15 | `with UPDATE orconf ...` | `[WITH ...] UPDATE [OR ...] ... SET ... [WHERE ...]` | **Rewrite** | DML |
| 16 | `with insert_cmd INTO ... select upsert` | `[WITH ...] INSERT/REPLACE INTO ... VALUES/SELECT ... [ON CONFLICT ...]` | **Rewrite** | DML |
| 17 | `with insert_cmd INTO ... DEFAULT VALUES` | `[WITH ...] INSERT INTO ... DEFAULT VALUES` | **Rewrite** | DML |
| 18 | `createkw uniqueflag INDEX ...` | `CREATE [UNIQUE] INDEX ...` | Unsupported | DDL |
| 19 | `DROP INDEX ifexists fullname` | `DROP INDEX [IF EXISTS] name` | Unsupported | DDL |
| 20 | `VACUUM vinto` | `VACUUM [INTO file]` | Unsupported | Utility |
| 21 | `VACUUM nm vinto` | `VACUUM schema [INTO file]` | Unsupported | Utility |
| 22 | `PRAGMA nm dbnm` | `PRAGMA name` | Unsupported | Utility |
| 23 | `PRAGMA nm dbnm EQ nmnum` | `PRAGMA name = value` | Unsupported | Utility |
| 24 | `PRAGMA nm dbnm LP nmnum RP` | `PRAGMA name(value)` | Unsupported | Utility |
| 25 | `PRAGMA nm dbnm EQ minus_num` | `PRAGMA name = -value` | Unsupported | Utility |
| 26 | `PRAGMA nm dbnm LP minus_num RP` | `PRAGMA name(-value)` | Unsupported | Utility |
| 27 | `createkw trigger_decl BEGIN ... END` | `CREATE TRIGGER ...` | Unsupported | DDL |
| 28 | `DROP TRIGGER ifexists fullname` | `DROP TRIGGER [IF EXISTS] name` | Unsupported | DDL |
| 29 | `ATTACH database_kw_opt expr AS expr key_opt` | `ATTACH [DATABASE] file AS name` | Unsupported | Utility |
| 30 | `DETACH database_kw_opt expr` | `DETACH [DATABASE] name` | Unsupported | Utility |
| 31 | `REINDEX` | `REINDEX` | Unsupported | Utility |
| 32 | `REINDEX nm dbnm` | `REINDEX name` | Unsupported | Utility |
| 33 | `ANALYZE` | `ANALYZE` | Unsupported | Utility |
| 34 | `ANALYZE nm dbnm` | `ANALYZE name` | Unsupported | Utility |
| 35 | `ALTER TABLE fullname RENAME TO nm` | `ALTER TABLE ... RENAME TO ...` | **Rewrite** | DDL |
| 36 | `ALTER TABLE ... ADD kwcolumn_opt columnname carglist` | `ALTER TABLE ... ADD [COLUMN] ...` | **Rewrite** | DDL |
| 37 | `ALTER TABLE fullname DROP kwcolumn_opt nm` | `ALTER TABLE ... DROP [COLUMN] ...` | **Rewrite** | DDL |
| 38 | `ALTER TABLE fullname RENAME kwcolumn_opt nm TO nm` | `ALTER TABLE ... RENAME [COLUMN] ... TO ...` | **Rewrite** | DDL |
| 39 | `create_vtab` | `CREATE VIRTUAL TABLE ...` | Unsupported | DDL |
| 40 | `create_vtab LP vtabarglist RP` | `CREATE VIRTUAL TABLE ... USING module(args)` | Unsupported | DDL |

**Additional:** `explain` rule wraps `cmd`:
| | `EXPLAIN cmd` | `EXPLAIN ...` | Unsupported | Utility |
| | `EXPLAIN QUERY PLAN cmd` | `EXPLAIN QUERY PLAN ...` | Unsupported | Utility |

**Summary:** 13 Rewrite + 0 Ignored + 27 Unsupported = 40 total (+ 2 EXPLAIN wrappers).

---

## Multiple Statements

| Mode | Behavior |
|------|----------|
| Rewrite | Process each statement sequentially via `rewriteMultiple()` |

**Grammar:** The `input` -> `cmdlist` -> `ecmd` -> `cmdx` chain handles semicolon-separated statements. `cmdlist` is recursive: `cmdlist ecmd | ecmd`. Each `ecmd` is `SEMI | cmdx SEMI | explain cmdx SEMI`.

**Supported:**
```sql
SELECT 1; SELECT 2
INSERT INTO t VALUES (1); UPDATE t SET x = 2
```

**Result:** `MultiRewritePlan` containing individual `RewritePlan` for each statement.

**Result Retrieval:** Use `nextRowset()` to iterate through result sets.

**Parser Note:** The SQLite parser handles single-quoted strings (with `''` escaping), double-quoted identifiers (with `""` escaping), backtick-quoted identifiers, bracket-quoted identifiers (`[name]`), line comments (`--`), and block comments (`/* */`) when splitting statements by semicolons.

---

## CTE Shadowing Details (SQLite-Specific)

### UNION ALL Chains

SQLite uses `SELECT ... UNION ALL SELECT ...` chains for all multi-row CTEs:

```sql
WITH "users" AS (
  SELECT CAST(1 AS INTEGER) AS "id", CAST('Alice' AS TEXT) AS "name"
  UNION ALL
  SELECT CAST(2 AS INTEGER) AS "id", CAST('Bob' AS TEXT) AS "name"
)
SELECT * FROM "users"
```

**Design Decision:** Unlike PostgreSQL (which uses `VALUES` clause) and MySQL (which uses `UNION ALL`), SQLite uses `UNION ALL` chains. SQLite does not use `AS MATERIALIZED` since SQLite's CTE implementation does not inline CTEs by default (pre-3.35) and the keyword is not supported.

### Empty CTEs

For tables with no fixture data:

```sql
WITH "users" AS (SELECT CAST(NULL AS INTEGER) AS "id", CAST(NULL AS TEXT) AS "name" WHERE 0)
```

Note: SQLite uses `WHERE 0` instead of PostgreSQL's `WHERE FALSE`.

### Identifier Quoting

SQLite supports multiple quoting styles. ZTD uses double-quote identifiers (`"identifier"`), which is the SQL standard:

```sql
-- Table name: my table
SELECT * FROM "my table"
```

The parser also recognizes backtick-quoted (`` `identifier` ``) and bracket-quoted (`[identifier]`) identifiers when reading, but generates double-quoted identifiers for output.

---

## Type Mapping

### ColumnTypeFamily to SQLite CAST Types

SQLite has a simplified type affinity system with five storage classes: NULL, INTEGER, REAL, TEXT, BLOB.

| ColumnTypeFamily | SQLite CAST Type |
|------------------|-----------------|
| `INTEGER` | `INTEGER` |
| `FLOAT` | `REAL` |
| `DOUBLE` | `REAL` |
| `DECIMAL` | `NUMERIC` |
| `STRING` | `TEXT` |
| `TEXT` | `TEXT` |
| `BOOLEAN` | `INTEGER` |
| `DATE` | `TEXT` |
| `TIME` | `TEXT` |
| `DATETIME` | `TEXT` |
| `TIMESTAMP` | `TEXT` |
| `BINARY` | `BLOB` |
| `JSON` | `TEXT` |
| `UNKNOWN` | Mapped from native type or `TEXT` fallback |

**Design Decision:** SQLite stores dates, times, booleans, and JSON as TEXT. ZTD's CAST expressions reflect this by casting to TEXT for temporal and JSON types, and INTEGER for booleans.

### Native Type to ColumnTypeFamily Mapping

| SQLite Native Type | ColumnTypeFamily |
|-------------------|------------------|
| `INT`, `INTEGER`, `TINYINT`, `SMALLINT`, `MEDIUMINT`, `BIGINT`, `INT2`, `INT8` | `INTEGER` |
| `REAL`, `DOUBLE`, `DOUBLE PRECISION`, `FLOAT` | `FLOAT` |
| `DECIMAL`, `NUMERIC` | `DECIMAL` |
| `CHAR`, `CHARACTER`, `VARCHAR`, `VARYING CHARACTER`, `NCHAR`, `NATIVE CHARACTER`, `NVARCHAR` | `STRING` |
| `TEXT`, `CLOB` | `TEXT` |
| `BOOLEAN`, `BOOL` | `BOOLEAN` |
| `BLOB` | `BINARY` |
| `DATE` | `DATE` |
| `TIME` | `TIME` |
| `DATETIME` | `DATETIME` |
| `TIMESTAMP` | `TIMESTAMP` |
| `JSON` | `JSON` |

---

## Error Handling

### Error Classification

SQLite errors are classified using error codes and message patterns:

| Error Code | Meaning | Classification |
|------------|---------|----------------|
| `1` (SQLITE_ERROR) | General SQL error | Schema error (if message matches) |

**Message Pattern Matching:**
- `no such table: ...` - Schema error
- `no such column: ...` - Schema error
- `table ... has no column named ...` - Schema error

### Schema Errors

#### Non-existent Table

| Operation | Detection | Behavior |
|-----------|-----------|----------|
| SELECT FROM unknown_table | ZTD | `UnknownSchemaException` |
| INSERT INTO unknown_table | ZTD | `UnknownSchemaException` |
| UPDATE unknown_table | ZTD | `UnknownSchemaException` |
| DELETE FROM unknown_table | ZTD | `UnknownSchemaException` |
| DROP TABLE unknown_table | ZTD | `UnknownSchemaException` |
| ALTER TABLE unknown_table | ZTD | `UnknownSchemaException` |

#### Table/Column Already Exists

| Operation | Detection | Behavior |
|-----------|-----------|----------|
| CREATE TABLE existing_table | ZTD | `UnsupportedSqlException` (Table already exists) |
| CREATE TABLE IF NOT EXISTS | ZTD | No action (table already exists) |

### Data Type Errors

ZTD applies CAST to all values using SQLite's type affinity system.

**Example:**
```sql
-- Schema: users (id INTEGER, name TEXT, active BOOLEAN)
-- Original
INSERT INTO users (id, name, active) VALUES ('123', 'Alice', 1)

-- Result Select (with CAST)
SELECT
  CAST('123' AS INTEGER) AS "id",
  CAST('Alice' AS TEXT) AS "name",
  CAST(1 AS INTEGER) AS "active"
```

### Schema Reflection

SQLite schema is reflected via `sqlite_master`:

- **CREATE statements:** `SELECT sql FROM sqlite_master WHERE type='table' AND name=?`
- **All tables:** `SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'`

The original `CREATE TABLE` SQL is stored directly in `sqlite_master.sql`, so no reconstruction is needed (unlike PostgreSQL).

---

## Unsupported SQL Handling

When an unsupported SQL statement is executed, behavior depends on configuration.

### Behavior Modes

| Mode | Description |
|------|-------------|
| `ignore` | Silently ignore, return empty result |
| `notice` | Log warning, continue with empty result |
| `exception` | Throw `UnsupportedSqlException` |
| `passthrough` | Execute against real database as-is |

### Per-Statement Configuration

| Pattern | Behavior | Reason |
|---------|----------|--------|
| `PRAGMA ...` | `passthrough` or `ignore` | SQLite configuration |
| `CREATE INDEX` | `ignore` | Index management not needed in tests |
| `BEGIN/COMMIT/ROLLBACK` | `passthrough` | Transaction management |
| Default | `ignore` | Fallback for unspecified patterns |

---

## Limitations

1. **No TRUNCATE TABLE** - Use `DELETE FROM table` (without WHERE) instead. ZTD treats this as a truncation.
2. **No multi-table DELETE** - SQLite only supports single-table DELETE (no USING clause).
3. **No RIGHT/FULL OUTER JOIN** - SQLite does not support RIGHT or FULL OUTER JOIN (LEFT JOIN and CROSS JOIN are supported).
4. **No RETURNING clause** - ZTD uses Result Select Query approach instead.
5. **No AS MATERIALIZED** - SQLite does not support the `MATERIALIZED` keyword in CTEs (unlike PostgreSQL).
6. **No VALUES clause in CTEs** - Multi-row CTEs use `UNION ALL` chains instead.
7. **No CREATE TABLE ... AS SELECT** - Grammar supports it but mutation resolver does not implement it.
8. **TCL not ignored** - Unlike MySQL/PostgreSQL, transaction statements are treated as unsupported, not silently ignored.
9. **Limited ALTER TABLE** - Only ADD COLUMN, DROP COLUMN, RENAME TABLE, and RENAME COLUMN are supported (all 4 grammar alternatives).
10. **No stored procedures / functions** - SQLite has no server-side programming.
11. **CHECK constraints** - Not evaluated by ZTD.
12. **Triggers** - Not simulated.
13. **Type affinity** - SQLite's flexible typing means CAST may behave differently than strict-typed databases.
14. **Virtual tables** - FTS, R-Tree, and other virtual table modules are unsupported.

---

## Design Principles

1. **Zero Physical Impact** - All operations are virtual; physical tables are never modified
2. **SQL as Pure Functions** - Input (fixtures) and output (query results) only, no side effects
3. **Real Database Engine** - Uses actual SQLite database for SQL parsing, type inference, and query execution
4. **CTE-Based Isolation** - Tables are shadowed with CTEs, not mocked
5. **Simplicity** - Complex features (triggers, views, virtual tables) are out of scope
6. **Type Conversion via CAST** - Schema metadata used to apply appropriate type casts using SQLite's affinity system
7. **Result Select over RETURNING** - Consistent cross-platform approach; does not rely on SQLite 3.35+ `RETURNING`
