<?php
/*!
 * Medoo database framework
 * http://medoo.in
 * Version 1.1.3
 *
 * Copyright 2016, Angel Lai
 * Released under the MIT license
 */
class Medoo
{
	// General
	protected $database_type;

	protected $charset;

	protected $database_name;

	// For MySQL, MariaDB, MSSQL, Sybase, PostgreSQL, Oracle
	protected $server;

	protected $username;

	protected $password;

	// For SQLite
	protected $database_file;

	// For MySQL or MariaDB with unix_socket
	protected $socket;

	// Optional
	protected $port;

	protected $prefix;

	protected $option = array();

	// Variable
	protected $logs = array();

	protected $debug_mode = false;

	public function __construct($options = null)
	{
		try {
			$commands = array();
			$dsn = '';

			if (is_array($options))
			{
				foreach ($options as $option => $value)
				{
					$this->$option = $value;
				}
			}
			else
			{
				return false;
			}

			if (
				isset($this->port) &&
				is_int($this->port * 1)
			)
			{
				$port = $this->port;
			}

			$type = strtolower($this->database_type);
			$is_port = isset($port);

			if (isset($options[ 'prefix' ]))
			{
				$this->prefix = $options[ 'prefix' ];
			}

			switch ($type)
			{
				case 'mariadb':
					$type = 'mysql';

				case 'mysql':
					if ($this->socket)
					{
						$dsn = $type . ':unix_socket=' . $this->socket . ';dbname=' . $this->database_name;
					}
					else
					{
						$dsn = $type . ':host=' . $this->server . ($is_port ? ';port=' . $port : '') . ';dbname=' . $this->database_name;
					}

					// Make MySQL using standard quoted identifier
					$commands[] = 'SET SQL_MODE=ANSI_QUOTES';
					break;

				case 'pgsql':
					$dsn = $type . ':host=' . $this->server . ($is_port ? ';port=' . $port : '') . ';dbname=' . $this->database_name;
					break;

				case 'sybase':
					$dsn = 'dblib:host=' . $this->server . ($is_port ? ':' . $port : '') . ';dbname=' . $this->database_name;
					break;

				case 'oracle':
					$dbname = $this->server ?
						'//' . $this->server . ($is_port ? ':' . $port : ':1521') . '/' . $this->database_name :
						$this->database_name;

					$dsn = 'oci:dbname=' . $dbname . ($this->charset ? ';charset=' . $this->charset : '');
					break;

				case 'mssql':
					$dsn = strstr(PHP_OS, 'WIN') ?
						'sqlsrv:server=' . $this->server . ($is_port ? ',' . $port : '') . ';database=' . $this->database_name :
						'dblib:host=' . $this->server . ($is_port ? ':' . $port : '') . ';dbname=' . $this->database_name;

					// Keep MSSQL QUOTED_IDENTIFIER is ON for standard quoting
					$commands[] = 'SET QUOTED_IDENTIFIER ON';
					break;

				case 'sqlite':
					$dsn = $type . ':' . $this->database_file;
					$this->username = null;
					$this->password = null;
					break;
			}

			if (
				in_array($type, array('mariadb', 'mysql', 'pgsql', 'sybase', 'mssql')) &&
				$this->charset
			)
			{
				$commands[] = "SET NAMES '" . $this->charset . "'";
			}

			$this->pdo = new PDO(
				$dsn,
				$this->username,
				$this->password,
				$this->option
			);

			foreach ($commands as $value)
			{
				$this->pdo->exec($value);
			}
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function query($query)
	{
		if ($this->debug_mode)
		{
			echo $query;

			$this->debug_mode = false;

			return false;
		}

		$this->logs[] = $query;

		return $this->pdo->query($query);
	}

	public function exec($query)
	{
		if ($this->debug_mode)
		{
			echo $query;

			$this->debug_mode = false;

			return false;
		}

		$this->logs[] = $query;

		return $this->pdo->exec($query);
	}

	public function quote($string)
	{
		return $this->pdo->quote($string);
	}

	protected function table_quote($table)
	{
		return '"' . $this->prefix . $table . '"';
	}

	protected function column_quote($string)
	{
		preg_match('/(\(JSON\)\s*|^#)?([a-zA-Z0-9_]*)\.([a-zA-Z0-9_]*)/', $string, $column_match);

		if (isset($column_match[ 2 ], $column_match[ 3 ]))
		{
			return '"' . $this->prefix . $column_match[ 2 ] . '"."' . $column_match[ 3 ] . '"';
		}

		return '"' . $string . '"';
	}

	protected function column_push(&$columns)
	{
		if ($columns == '*')
		{
			return $columns;
		}

		if (is_string($columns))
		{
			$columns = array($columns);
		}

		$stack = array();

		foreach ($columns as $key => $value)
		{
			if (is_array($value))
			{
				$stack[] = $this->column_push($value);
			}
			else
			{
				preg_match('/([a-zA-Z0-9_\-\.]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $value, $match);

				if (isset($match[ 1 ], $match[ 2 ]))
				{
					$stack[] = $this->column_quote( $match[ 1 ] ) . ' AS ' . $this->column_quote( $match[ 2 ] );

					$columns[ $key ] = $match[ 2 ];
				}
				else
				{
					$stack[] = $this->column_quote( $value );
				}
			}
		}

		return implode($stack, ',');
	}

	protected function array_quote($array)
	{
		$temp = array();

		foreach ($array as $value)
		{
			$temp[] = is_int($value) ? $value : $this->pdo->quote($value);
		}

		return implode($temp, ',');
	}

	protected function inner_conjunct($data, $conjunctor, $outer_conjunctor)
	{
		$haystack = array();

		foreach ($data as $value)
		{
			$haystack[] = '(' . $this->data_implode($value, $conjunctor) . ')';
		}

		return implode($outer_conjunctor . ' ', $haystack);
	}

	protected function fn_quote($column, $string)
	{
		return (strpos($column, '#') === 0 && preg_match('/^[A-Z0-9\_]*\([^)]*\)$/', $string)) ?

			$string :

			$this->quote($string);
	}

	protected function data_implode($data, $conjunctor, $outer_conjunctor = null)
	{
		$wheres = array();
		foreach ($data as $key => $value)
		{
			$type = gettype($value);

			if (
				preg_match("/^(AND|OR)(\s+#.*)?$/i", $key, $relation_match) &&
				$type == 'array'
			)
			{
				$wheres[] = 0 !== count(array_diff_key($value, array_keys(array_keys($value)))) ?
					'(' . $this->data_implode($value, ' ' . $relation_match[ 1 ]) . ')' :
					'(' . $this->inner_conjunct($value, ' ' . $relation_match[ 1 ], $conjunctor) . ')';
			}
			else
			{
				preg_match('/(#?)([\w\.\-]+)(\[(\>|\>\=|\<|\<\=|\!|\<\>|\>\<|\!?~)\])?/i', $key, $match);
				$column = $this->column_quote($match[ 2 ]);

				if (isset($match[ 4 ]))
				{
					$operator = $match[ 4 ];

					if ($operator == '!')
					{
						switch ($type)
						{
							case 'NULL':
								$wheres[] = $column . ' IS NOT NULL';
								break;

							case 'array':
								$wheres[] = $column . ' NOT IN (' . $this->array_quote($value) . ')';
								break;

							case 'integer':
							case 'double':
								$wheres[] = $column . ' != ' . $value;
								break;

							case 'boolean':
								$wheres[] = $column . ' != ' . ($value ? '1' : '0');
								break;

							case 'string':
								$wheres[] = $column . ' != ' . $this->fn_quote($key, $value);
								break;
						}
					}

					if ($operator == '<>' || $operator == '><')
					{
						if ($type == 'array')
						{
							if ($operator == '><')
							{
								$column .= ' NOT';
							}

							if (is_numeric($value[ 0 ]) && is_numeric($value[ 1 ]))
							{
								$wheres[] = '(' . $column . ' BETWEEN ' . $value[ 0 ] . ' AND ' . $value[ 1 ] . ')';
							}
							else
							{
								$wheres[] = '(' . $column . ' BETWEEN ' . $this->quote($value[ 0 ]) . ' AND ' . $this->quote($value[ 1 ]) . ')';
							}
						}
					}

					if ($operator == '~' || $operator == '!~')
					{
						if ($type != 'array')
						{
							$value = array($value);
						}

						$like_clauses = array();

						foreach ($value as $item)
						{
							$item = strval($item);

							if (preg_match('/^(?!(%|\[|_])).+(?<!(%|\]|_))$/', $item))
							{
								$item = '%' . $item . '%';
							}

							$like_clauses[] = $column . ($operator === '!~' ? ' NOT' : '') . ' LIKE ' . $this->fn_quote($key, $item);
						}

						$wheres[] = implode(' OR ', $like_clauses);
					}

					if (in_array($operator, array('>', '>=', '<', '<=')))
					{
						$condition = $column . ' ' . $operator . ' ';

						if (is_numeric($value))
						{
							$condition .= $value;
						}
						elseif (strpos($key, '#') === 0)
						{
							$condition .= $this->fn_quote($key, $value);
						}
						else
						{
							$condition .= $this->quote($value);
						}

						$wheres[] = $condition;
					}
				}
				else
				{
					switch ($type)
					{
						case 'NULL':
							$wheres[] = $column . ' IS NULL';
							break;

						case 'array':
							$wheres[] = $column . ' IN (' . $this->array_quote($value) . ')';
							break;

						case 'integer':
						case 'double':
							$wheres[] = $column . ' = ' . $value;
							break;

						case 'boolean':
							$wheres[] = $column . ' = ' . ($value ? '1' : '0');
							break;

						case 'string':
							$wheres[] = $column . ' = ' . $this->fn_quote($key, $value);
							break;
					}
				}
			}
		}
		return implode($conjunctor . ' ', $wheres);
	}

	protected function where_clause($where)
	{
		$where_clause = '';

		if (is_array($where))
		{
			$where_keys = array_keys($where);
			$where_AND = preg_grep("/^AND\s*#?$/i", $where_keys);
			$where_OR = preg_grep("/^OR\s*#?$/i", $where_keys);

			$single_condition = array_diff_key($where, array_flip(
				array('AND', 'OR', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'LIKE', 'MATCH')
			));

			if ($single_condition != array())
			{
				$condition = $this->data_implode($single_condition, '');

				if ($condition != '')
				{
					$where_clause = ' WHERE ' . $condition;
				}
			}

			if (!empty($where_AND))
			{
				$value = array_values($where_AND);
				if (count($where[$value[0]]) != 0) {
					$where_clause = ' WHERE ' . $this->data_implode($where[ $value[ 0 ] ], ' AND');
				}
			}

			if (!empty($where_OR))
			{
				$value = array_values($where_OR);
				if (count($where[$value[0]]) != 0) {
					$where_clause = ' WHERE ' . $this->data_implode($where[ $value[ 0 ] ], ' OR');
				}
			}

			if (isset($where[ 'MATCH' ]))
			{
				$MATCH = $where[ 'MATCH' ];

				if (is_array($MATCH) && isset($MATCH[ 'columns' ], $MATCH[ 'keyword' ]))
				{
					$where_clause .= ($where_clause != '' ? ' AND ' : ' WHERE ') . ' MATCH ("' . str_replace('.', '"."', implode($MATCH[ 'columns' ], '", "')) . '") AGAINST (' . $this->quote($MATCH[ 'keyword' ]) . ')';
				}
			}

			if (isset($where[ 'GROUP' ]))
			{
				$where_clause .= ' GROUP BY ' . $this->column_quote($where[ 'GROUP' ]);

				if (isset($where[ 'HAVING' ]))
				{
					$where_clause .= ' HAVING ' . $this->data_implode($where[ 'HAVING' ], ' AND');
				}
			}

			if (isset($where[ 'ORDER' ]))
			{
				$ORDER = $where[ 'ORDER' ];

				if (is_array($ORDER))
				{
					$stack = array();

					foreach ($ORDER as $column => $value)
					{
						if (is_array($value))
						{
							$stack[] = 'FIELD(' . $this->column_quote($column) . ', ' . $this->array_quote($value) . ')';
						}
						else if ($value === 'ASC' || $value === 'DESC')
						{
							$stack[] = $this->column_quote($column) . ' ' . $value;
						}
						else if (is_int($column))
						{
							$stack[] = $this->column_quote($value);
						}
					}

					$where_clause .= ' ORDER BY ' . implode($stack, ',');
				}
				else
				{
					$where_clause .= ' ORDER BY ' . $this->column_quote($ORDER);
				}
			}

			if (isset($where[ 'LIMIT' ]))
			{
				$LIMIT = $where[ 'LIMIT' ];

				if (is_numeric($LIMIT))
				{
					$where_clause .= ' LIMIT ' . $LIMIT;
				}

				if (
					is_array($LIMIT) &&
					is_numeric($LIMIT[ 0 ]) &&
					is_numeric($LIMIT[ 1 ])
				)
				{
					if ($this->database_type === 'pgsql')
					{
						$where_clause .= ' OFFSET ' . $LIMIT[ 0 ] . ' LIMIT ' . $LIMIT[ 1 ];
					}
					else
					{
						$where_clause .= ' LIMIT ' . $LIMIT[ 0 ] . ',' . $LIMIT[ 1 ];
					}
				}
			}
		}
		else
		{
			if ($where != null)
			{
				$where_clause .= ' ' . $where;
			}
		}
		return $where_clause;
	}

	protected function select_context($table, $join, &$columns = null, $where = null, $column_fn = null)
	{

		preg_match('/([a-zA-Z0-9_\-]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $table, $table_match);

		if (isset($table_match[ 1 ], $table_match[ 2 ]))
		{
			$table = $this->table_quote($table_match[ 1 ]);

			$table_query = $this->table_quote($table_match[ 1 ]) . ' AS ' . $this->table_quote($table_match[ 2 ]);
		}
		else
		{
			$table = $this->table_quote($table);

			$table_query = $table;
		}

		$join_key = is_array($join) ? array_keys($join) : null;

		if (
			isset($join_key[ 0 ]) &&
			strpos($join_key[ 0 ], '[') === 0
		)
		{
			$table_join = array();

			$join_array = array(
				'>' => 'LEFT',
				'<' => 'RIGHT',
				'<>' => 'FULL',
				'><' => 'INNER'
			);

			foreach($join as $sub_table => $relation)
			{
				preg_match('/(\[(\<|\>|\>\<|\<\>)\])?([a-zA-Z0-9_\-]*)\s?(\(([a-zA-Z0-9_\-]*)\))?/', $sub_table, $match);

				if ($match[ 2 ] != '' && $match[ 3 ] != '')
				{
					if (is_string($relation))
					{
						$relation = 'USING ("' . $relation . '")';
					}

					if (is_array($relation))
					{
						// For ['column1', 'column2']
						if (isset($relation[ 0 ]))
						{
							$relation = 'USING ("' . implode($relation, '", "') . '")';
						}
						else
						{
							$joins = array();

							foreach ($relation as $key => $value)
							{
								$joins[] = (
									strpos($key, '.') > 0 ?
										// For ['tableB.column' => 'column']
										$this->column_quote($key) :

										// For ['column1' => 'column2']
										$table . '."' . $key . '"'
								) .
								' = ' .
								$this->table_quote(isset($match[ 5 ]) ? $match[ 5 ] : $match[ 3 ]) . '."' . $value . '"';
							}

							$relation = 'ON ' . implode($joins, ' AND ');
						}
					}

					$table_name = $this->table_quote($match[ 3 ]) . ' ';

					if (isset($match[ 5 ]))
					{
						$table_name .= 'AS ' . $this->table_quote($match[ 5 ]) . ' ';
					}

					$table_join[] = $join_array[ $match[ 2 ] ] . ' JOIN ' . $table_name . $relation;
				}
			}

			$table_query .= ' ' . implode($table_join, ' ');
		}
		else
		{
			if (is_null($columns))
			{
				if (is_null($where))
				{
					if (
						is_array($join) &&
						isset($column_fn)
					)
					{
						$where = $join;
						$columns = null;
					}
					else
					{
						$where = null;
						$columns = $join;
					}
				}
				else
				{
					$where = $join;
					$columns = null;
				}
			}
			else
			{
				$where = $columns;
				$columns = $join;
			}
		}

		if (isset($column_fn))
		{
			if ($column_fn == 1)
			{
				$column = '1';

				if (is_null($where))
				{
					$where = $columns;
				}
			}
			else
			{
				if (empty($columns))
				{
					$columns = '*';
					$where = $join;
				}

				$column = $column_fn . '(' . $this->column_push($columns) . ')';
			}
		}
		else
		{
			$column = $this->column_push($columns);
		}

		return 'SELECT ' . $column . ' FROM ' . $table_query . $this->where_clause($where);
	}

	protected function data_map($index, $key, $value, $data, &$stack)
	{
		if (is_array($value))
		{
			$sub_stack = array();

			foreach ($value as $sub_key => $sub_value)
			{
				if (is_array($sub_value))
				{
					$current_stack = $stack[ $index ][ $key ];

					$this->data_map(false, $sub_key, $sub_value, $data, $current_stack);

					$stack[ $index ][ $key ][ $sub_key ] = $current_stack[ 0 ][ $sub_key ];
				}
				else
				{
					$this->data_map(false, preg_replace('/^[\w]*\./i', "", $sub_value), $sub_key, $data, $sub_stack);

					$stack[ $index ][ $key ] = $sub_stack;
				}
			}
		}
		else
		{
			if ($index !== false)
			{
				$stack[ $index ][ $value ] = $data[ $value ];
			}
			else
			{
				if (preg_match('/[a-zA-Z0-9_\-\.]*\s*\(([a-zA-Z0-9_\-]*)\)/i', $key, $key_match))
				{
					$key = $key_match[ 1 ];
				}

				$stack[ $key ] = $data[ $key ];
			}
		}
	}

	public function select($table, $join, $columns = null, $where = null)
	{
		$column = $where == null ? $join : $columns;

		$is_single_column = (is_string($column) && $column !== '*');
		
		$query = $this->query($this->select_context($table, $join, $columns, $where));

		$stack = array();

		$index = 0;

		if (!$query)
		{
			return false;
		}

		if ($columns === '*')
		{
			return $query->fetchAll(PDO::FETCH_ASSOC);
		}

		if ($is_single_column)
		{
			return $query->fetchAll(PDO::FETCH_COLUMN);
		}

		while ($row = $query->fetch(PDO::FETCH_ASSOC))
		{
			foreach ($columns as $key => $value)
			{
				if (is_array($value))
				{
					$this->data_map($index, $key, $value, $row, $stack);
				}
				else
				{
					$this->data_map($index, $key, preg_replace('/^[\w]*\./i', "", $value), $row, $stack);
				}
			}

			$index++;
		}

		return $stack;
	}

	public function insert($table, $datas)
	{
		$lastId = array();

		// Check indexed or associative array
		if (!isset($datas[ 0 ]))
		{
			$datas = array($datas);
		}

		foreach ($datas as $data)
		{
			$values = array();
			$columns = array();

			foreach ($data as $key => $value)
			{
				$columns[] = $this->column_quote(preg_replace("/^(\(JSON\)\s*|#)/i", "", $key));

				switch (gettype($value))
				{
					case 'NULL':
						$values[] = 'NULL';
						break;

					case 'array':
						preg_match("/\(JSON\)\s*([\w]+)/i", $key, $column_match);

						$values[] = isset($column_match[ 0 ]) ?
							$this->quote(json_encode($value)) :
							$this->quote(serialize($value));
						break;

					case 'boolean':
						$values[] = ($value ? '1' : '0');
						break;

					case 'integer':
					case 'double':
					case 'string':
						$values[] = $this->fn_quote($key, $value);
						break;
				}
			}

			$this->exec('INSERT INTO ' . $this->table_quote($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode($values, ', ') . ')');

			$lastId[] = $this->pdo->lastInsertId();
		}

		return count($lastId) > 1 ? $lastId : $lastId[ 0 ];
	}

	public function update($table, $data, $where = null)
	{
		$fields = array();

		foreach ($data as $key => $value)
		{
			preg_match('/([\w]+)(\[(\+|\-|\*|\/)\])?/i', $key, $match);

			if (isset($match[ 3 ]))
			{
				if (is_numeric($value))
				{
					$fields[] = $this->column_quote($match[ 1 ]) . ' = ' . $this->column_quote($match[ 1 ]) . ' ' . $match[ 3 ] . ' ' . $value;
				}
			}
			else
			{
				$column = $this->column_quote(preg_replace("/^(\(JSON\)\s*|#)/i", "", $key));

				switch (gettype($value))
				{
					case 'NULL':
						$fields[] = $column . ' = NULL';
						break;

					case 'array':
						preg_match("/\(JSON\)\s*([\w]+)/i", $key, $column_match);

						$fields[] = $column . ' = ' . $this->quote(
								isset($column_match[ 0 ]) ? json_encode($value) : serialize($value)
							);
						break;

					case 'boolean':
						$fields[] = $column . ' = ' . ($value ? '1' : '0');
						break;

					case 'integer':
					case 'double':
					case 'string':
						$fields[] = $column . ' = ' . $this->fn_quote($key, $value);
						break;
				}
			}
		}

		return $this->exec('UPDATE ' . $this->table_quote($table) . ' SET ' . implode(', ', $fields) . $this->where_clause($where));
	}

	public function delete($table, $where)
	{
		return $this->exec('DELETE FROM ' . $this->table_quote($table) . $this->where_clause($where));
	}

	public function replace($table, $columns, $search = null, $replace = null, $where = null)
	{
		if (is_array($columns))
		{
			$replace_query = array();

			foreach ($columns as $column => $replacements)
			{
				foreach ($replacements as $replace_search => $replace_replacement)
				{
					$replace_query[] = $column . ' = REPLACE(' . $this->column_quote($column) . ', ' . $this->quote($replace_search) . ', ' . $this->quote($replace_replacement) . ')';
				}
			}

			$replace_query = implode(', ', $replace_query);
			$where = $search;
		}
		else
		{
			if (is_array($search))
			{
				$replace_query = array();

				foreach ($search as $replace_search => $replace_replacement)
				{
					$replace_query[] = $columns . ' = REPLACE(' . $this->column_quote($columns) . ', ' . $this->quote($replace_search) . ', ' . $this->quote($replace_replacement) . ')';
				}

				$replace_query = implode(', ', $replace_query);
				$where = $replace;
			}
			else
			{
				$replace_query = $columns . ' = REPLACE(' . $this->column_quote($columns) . ', ' . $this->quote($search) . ', ' . $this->quote($replace) . ')';
			}
		}

		return $this->exec('UPDATE ' . $this->table_quote($table) . ' SET ' . $replace_query . $this->where_clause($where));
	}

	public function get($table, $join = null, $columns = null, $where = null)
	{
		$column = $where == null ? $join : $columns;

		$is_single_column = (is_string($column) && $column !== '*');

		$query = $this->query($this->select_context($table, $join, $columns, $where) . ' LIMIT 1');

		if ($query)
		{
			$data = $query->fetchAll(PDO::FETCH_ASSOC);

			if (isset($data[ 0 ]))
			{
				if ($is_single_column)
				{
					return $data[ 0 ][ preg_replace('/^[\w]*\./i', "", $column) ];
				}
				
				if ($column === '*')
				{
					return $data[ 0 ];
				}

				$stack = array();

				foreach ($columns as $key => $value)
				{
					if (is_array($value))
					{
						$this->data_map(0, $key, $value, $data[ 0 ], $stack);
					}
					else
					{
						$this->data_map(0, $key, preg_replace('/^[\w]*\./i', "", $value), $data[ 0 ], $stack);
					}
				}

				return $stack[ 0 ];
			}
			else
			{
				return false;
			}
		}
		else
		{
			return false;
		}
	}

	public function has($table, $join, $where = null)
	{
		$column = null;

		$query = $this->query('SELECT EXISTS(' . $this->select_context($table, $join, $column, $where, 1) . ')');

		if ($query)
		{
			return $query->fetchColumn() === '1';
		}
		else
		{
			return false;
		}
	}

	public function count($table, $join = null, $column = null, $where = null)
	{
		$query = $this->query($this->select_context($table, $join, $column, $where, 'COUNT'));
		return $query ? 0 + $query->fetchColumn() : false;
	}

	public function max($table, $join, $column = null, $where = null)
	{
		$query = $this->query($this->select_context($table, $join, $column, $where, 'MAX'));

		if ($query)
		{
			$max = $query->fetchColumn();

			return is_numeric($max) ? $max + 0 : $max;
		}
		else
		{
			return false;
		}
	}

	public function min($table, $join, $column = null, $where = null)
	{
		$query = $this->query($this->select_context($table, $join, $column, $where, 'MIN'));

		if ($query)
		{
			$min = $query->fetchColumn();

			return is_numeric($min) ? $min + 0 : $min;
		}
		else
		{
			return false;
		}
	}

	public function avg($table, $join, $column = null, $where = null)
	{
		$query = $this->query($this->select_context($table, $join, $column, $where, 'AVG'));

		return $query ? 0 + $query->fetchColumn() : false;
	}

	public function sum($table, $join, $column = null, $where = null)
	{
		$query = $this->query($this->select_context($table, $join, $column, $where, 'SUM'));

		return $query ? 0 + $query->fetchColumn() : false;
	}

	public function action($actions)
	{
		if (is_callable($actions))
		{
			$this->pdo->beginTransaction();

			$result = $actions($this);

			if ($result === false)
			{
				$this->pdo->rollBack();
			}
			else
			{
				$this->pdo->commit();
			}
		}
		else
		{
			return false;
		}
	}

	public function debug()
	{
		$this->debug_mode = true;

		return $this;
	}

	public function error()
	{
		return $this->pdo->errorInfo();
	}

	public function last_query()
	{
		return end($this->logs);
	}

	public function log()
	{
		return $this->logs;
	}

	public function info()
	{
		$output = array(
			'server' => 'SERVER_INFO',
			'driver' => 'DRIVER_NAME',
			'client' => 'CLIENT_VERSION',
			'version' => 'SERVER_VERSION',
			'connection' => 'CONNECTION_STATUS'
		);

		foreach ($output as $key => $value)
		{
			$output[ $key ] = $this->pdo->getAttribute(constant('PDO::ATTR_' . $value));
		}

		return $output;
	}
	/* 返回带分页的列表
	* 默认分页数10条
	*/
	public function list($table,$columns = null, $where = null,$page = 1,$pagesize = 10,$join = null,$setpages = 10,$array = array())
	{
		$column = $where == null ? $join : $columns;
		$is_single_column = (is_string($column) && $column !== '*');

		// 分页用的
		$page = max(intval($page), 1);
		$offset = $pagesize*($page-1);
		// 查询总条数，计算当前页数等
		$number = $this->count($table, $where);
		$pages = $this->pages($number, $page, $pagesize, $array, $setpages);
		$where['LIMIT'] = [$offset,$pagesize];

		if (is_null($join)) {
			$query = $this->query($this->select_context($table,$columns, $where));
		}
		else
		{
			$query = $this->query($this->select_context($table,$join,$columns, $where));
		}


		$stack = array();

		$index = 0;

		if (!$query)
		{
			return false;
		}

		if ($columns === '*')
		{
			$stack = $query->fetchAll(PDO::FETCH_ASSOC);
		}

		if ($is_single_column)
		{
			$stack = $query->fetchAll(PDO::FETCH_COLUMN);
		}

		while ($row = $query->fetch(PDO::FETCH_ASSOC))
		{
			foreach ($columns as $key => $value)
			{
				if (is_array($value))
				{
					$this->data_map($index, $key, $value, $row, $stack);
				}
				else
				{
					$this->data_map($index, $key, preg_replace('/^[\w]*\./i', "", $value), $row, $stack);
				}
			}

			$index++;
		}

		$list = ['list'=>$stack,'pages'=>$pages];
		return $list;
	}
	/**
	 * 分页函数
	 *
	 * @param $num 信息总数
	 * @param $curr_page 当前分页
	 * @param $perpage 每页显示数
	 * @param $array 需要传递的数组，用于增加额外的方法
	 * @return 分页
	 */
	final public function pages($num, $curr_page, $perpage = 20, $array = array(),$setpages = 10) {
		$urlrule = $this->url_par('{$page}');
		$multipage = '';
		if($num > $perpage) {
			$page = $setpages+1;
			$offset = ceil($setpages/2-1);
			$pages = ceil($num / $perpage);
			if (defined('IN_ADMIN') && !defined('PAGES')) define('PAGES', $pages);
			$from = $curr_page - $offset;
			$to = $curr_page + $offset;
			$more = 0;
			if($page >= $pages) {
				$from = 2;
				$to = $pages-1;
			} else {
				if($from <= 1) {
					$to = $page-1;
					$from = 2;
				}  elseif($to >= $pages) {
					$from = $pages-($page-2);
					$to = $pages-1;
				}
				$more = 1;
			}
			$multipage .= '<a class="a1">共 '.ceil($num/$perpage).' 页</a>';
			if($curr_page>0) {
				$multipage .= ' <a href="'.$this->pageurl($urlrule, $curr_page-1, $array).'" class="a1">上一页</a>';
				if($curr_page==1) {
					$multipage .= ' <span>1</span>';
				} elseif($curr_page>6 && $more) {
					$multipage .= ' <a href="'.$this->pageurl($urlrule, 1, $array).'">1</a>';
				} else {
					$multipage .= ' <a href="'.$this->pageurl($urlrule, 1, $array).'">1</a>';
				}
			}
			for($i = $from; $i <= $to; $i++) {
				if($i != $curr_page) {
					$multipage .= ' <a href="'.$this->pageurl($urlrule, $i, $array).'">'.$i.'</a>';
				} else {
					$multipage .= ' <span>'.$i.'</span>';
				}
			}
			if($curr_page<$pages) {
				if($curr_page<$pages-5 && $more) {
					$multipage .= '<a href="'.$this->pageurl($urlrule, $pages, $array).'">'.$pages.'</a> <a href="'.$this->pageurl($urlrule, $curr_page+1, $array).'" class="a1">下一页</a>';
				} else {
					$multipage .= ' <a href="'.$this->pageurl($urlrule, $pages, $array).'">'.$pages.'</a> <a href="'.$this->pageurl($urlrule, $curr_page+1, $array).'" class="a1">下一页</a>';
				}
			} elseif($curr_page==$pages) {
				$multipage .= ' <span>'.$pages.'</span> <a href="'.$this->pageurl($urlrule, $curr_page, $array).'" class="a1">下一页</a>';
			} else {
				$multipage .= ' <a href="'.$this->pageurl($urlrule, $pages, $array).'">'.$pages.'</a> <a href="'.$this->pageurl($urlrule, $curr_page+1, $array).'" class="a1">下一页</a>';
			}
		}
		return $multipage;
	}
	/**
	 * 返回分页路径
	 *
	 * @param $urlrule 分页规则
	 * @param $page 当前页
	 * @param $array 需要传递的数组，用于增加额外的方法
	 * @return 完整的URL路径
	 */
	final public function pageurl($urlrule, $page, $array = array(), $restr = '') {
		if(strpos($urlrule, '~')) {
			$urlrules = explode('~', $urlrule);
			$urlrule = $page < 2 ? $urlrules[0] : $urlrules[1];
		}
		$findme = array('{$page}');
		$replaceme = array($page);
		if (is_array($array)) foreach ($array as $k=>$v) {
			$findme[] = '{$'.$k.'}';
			$replaceme[] = $v;
		}
		$url = str_replace($findme, $replaceme, $urlrule);
		$url = str_replace(array('http://','//','~',$restr), array('~','/','http://'), $url);
		return $url;
	}
	/**
	 * URL路径解析，pages 函数的辅助函数
	 *
	 * @param $par 传入需要解析的变量 默认为，page={$page}
	 * @param $url URL地址
	 * @return URL
	 */
	final public function url_par($par, $url = '') {
		if($url == '') $url = $this->get_url();

		$pos1 = strpos($url,'?');
		$pos = strpos($url, '?p=');
		$pos2 = strpos($url,'&p=');
		// 没有?及?p=的时候说时没有分页，直接加分页链接
		if ($pos1 === false && $pos === false) {
			$url .= '?p='.$par;
		}
		else
		{
			// 有?或者?p说明有其它的参数或者分页，直接添加&p
			if ($pos2 !== false) {
				$url = substr($url, 0, $pos2).'&p='.$par;
			}
			else
			{
				$url .= '&p='.$par;
			}
		}
		// 只有分页?p的时候改成?p
		if ($pos !== false && $pos1 !== false)
		{
			$url = substr($url, 0, $pos).'?p='.$par;
		}
		return $url;
	}
	/**
	 * 获取当前页面完整URL地址
	 */
	final public function get_url() {
		$sys_protocal = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443' ? 'https://' : 'http://';
		$php_self = $_SERVER['PHP_SELF'] ? $this->safe_replace($_SERVER['PHP_SELF']) : $this->safe_replace($_SERVER['SCRIPT_NAME']);
		$path_info = isset($_SERVER['PATH_INFO']) ? $this->safe_replace($_SERVER['PATH_INFO']) : '';
		$relate_url = isset($_SERVER['REQUEST_URI']) ? $this->safe_replace($_SERVER['REQUEST_URI']) : $php_self.(isset($_SERVER['QUERY_STRING']) ? '?'.$this->safe_replace($_SERVER['QUERY_STRING']) : $path_info);
		return $sys_protocal.(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '').$relate_url;
	}
	/**
	 * 安全过滤函数
	 *
	 * @param $string
	 * @return string
	 */
	final public function safe_replace($string) {
		$string = str_replace('%20','',$string);
		$string = str_replace('%27','',$string);
		$string = str_replace('%2527','',$string);
		$string = str_replace('*','',$string);
		$string = str_replace('"','&quot;',$string);
		$string = str_replace("'",'',$string);
		$string = str_replace('"','',$string);
		$string = str_replace(';','',$string);
		$string = str_replace('<','&lt;',$string);
		$string = str_replace('>','&gt;',$string);
		$string = str_replace("{",'',$string);
		$string = str_replace('}','',$string);
		$string = str_replace('\\','',$string);
		return $string;
	}
}
?>