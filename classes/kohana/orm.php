<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Object Relational Mapping
 * 
 * This class is a modified version of the standard Kohana ORM class
 * 
 * @author     SVat [svatphp@gmail.com] 2012
 * @author     Kohana Team
 * @copyright  (c) 2007-2010 Kohana Team
 * @license    http://kohanaframework.org/license
 */
class Kohana_ORM extends Database_Query_Builder_Select {

	/**
	 * Creates and returns a new model.
	 * 
	 * @param   string  $model  Model name
	 * @param   mixed   $id     Parameter for find()
	 * @return  ORM
	 */
	public static function factory($model, $id = NULL)
	{
		$model = 'Model_'.ucfirst($model);

		return new $model($id);
	}

	// Database
	protected $_db = NULL;

	// Model
	protected $_model_name = NULL;
	protected $_saved = FALSE;

	// Table
	protected $_table_name = NULL;
	protected $_table_names_plural = TRUE;
	protected $_table_columns = array();

	// Primary key
	protected $_primary_key = 'id';
	protected $_foreign_key_suffix = '_id';

	// Relationships
	protected $_has_one = array();
	protected $_has_many = array();
	protected $_belongs_to = array();

	// Data
	protected $_record = array();
	protected $_set = array();

	/**
	 * Constructs a new model and loads a record
	 * 
	 * @param   mixed $id Parameter for find
	 * @return  void
	 */
	public function __construct($id = NULL)
	{
		$this->_model_name = strtolower(substr(get_class($this), 6));

		if ( ! is_string($this->_table_name))
		{
			$this->_table_name = $this->_model_name;

			if ($this->_table_names_plural === TRUE)
			{
				$this->_table_name = Inflector::plural($this->_table_name);
			}
		}

		if ($id !== NULL)
		{
			if (is_array($id))
			{
				foreach ($id as $column => $value)
				{
					$this->where($column, '=', $value);
				}
			}
			else
			{
				$this->where($this->_primary_key, '=', $id);
			}

			$this->find();
		}

		$this->_prepare_relations();
	}

	/**
	 * Handles retrieval of all model values and relationships.
	 * 
	 * @param   string  column name
	 * @return  mixed
	 */
	public function __get($column)
	{
		if (isset($this->_record[$column]))
		{
			return $this->_record[$column];
		}
		elseif (isset($this->_belongs_to[$column]))
		{
			return ORM::factory(
				$this->_belongs_to[$column]['model'], 
				$this->_record[$this->_belongs_to[$column]['foreign_key']]
				);
		}
		elseif (isset($this->_has_one[$column]))
		{
			return ORM::factory($this->_has_one[$column]['model'])
				->where($this->_has_one[$column]['foreign_key'], '=', $this->primary_val())
				->find();
		}
		elseif (isset($this->_has_many[$column]))
		{
			$model = ORM::factory($this->_has_many[$column]['model']);

			if (isset($this->_has_many[$column]['through']))
			{
				$through = $this->_has_many[$column]['through'];

				$join_col1 = $through.'.'.$this->_has_many[$column]['far_key'];
				$join_col2 = $model->_table_name.'.'.$model->_primary_key;

				$model->join($through)->on($join_col1, '=', $join_col2);

				$col = $through.'.'.$this->_has_many[$column]['foreign_key'];
			}
			else
			{
				$col = $this->_has_many[$column]['foreign_key'];
			}

			return $model->where($col, '=', $this->primary_val());
		}
		else
		{
			throw new Kohana_Exception('The :property property does not exist in the :class class', 
				array(':property' => $column, ':class' => get_class($this)));
		}
	}

	/**
	 * Handles setting of all model values.
	 * 
	 * @param   string  column name
	 * @param   mixed   column value
	 * @return  void
	 */
	public function __set($column, $value)
	{
		if ($this->_model_name === NULL)
		{
			$this->_load_value($column, $value);
		}
		else
		{
			$this->set($column, $value);
		}
	}

	/**
	 * Sets the value
	 * 
	 * @param   string  column name
	 * @param   mixed   column value
	 * @return  $this
	 */
	public function set($column, $value)
	{
		if ($this->loaded())
		{
			if (isset($this->_record[$column]))
			{
				$this->_record[$column] = $this->_set[$column] = $value;
			}
		}
		else
		{
			$this->_set[$column] = $value;
		}

		return $this;
	}

	/**
	 * Sets the values from an array
	 * 
	 * @param  array  Array of column => value
	 * @return $this
	 */
	public function values(array $values)
	{
		foreach ($values as $column => $value)
		{
			$this->set($column, $value);
		}

		return $this;
	}

	/**
	 * Binds another one-to-one object to this model.  One-to-one objects
	 * can be nested using 'object1:object2' syntax
	 *
	 * @param  string  Target model to bind to
	 * @return $this
	 */
	public function with($target_path)
	{
		$aliases = explode(':', $target_path);

		$parent_model = $this;
		$parent_path = $this->_table_name;

		foreach ($aliases as $key => $alias)
		{
			$target_model = ORM::factory($alias);

			$target_path = ($key > 0) ? $target_path.':'.$alias : $alias;

			$table_columns = array_keys($target_model->_table_columns);

			foreach ($table_columns as $column)
			{
				$this->select(array($target_path.'.'.$column, $target_path.':'.$column));
			}

			if (isset($parent_model->_belongs_to[$alias]))
			{
				$join_col1 = $target_path.'.'.$target_model->_primary_key;
				$join_col2 = $parent_path.'.'.$parent_model->_belongs_to[$alias]['foreign_key'];
			}
			else
			{
				$join_col1 = $parent_path.'.'.$parent_model->_primary_key;
				$join_col2 = $target_path.'.'.$parent_model->_has_one[$alias]['foreign_key'];
			}

			$this->join(array($target_model->_table_name, $target_path), 'LEFT')->on($join_col1, '=', $join_col2);

			$parent_model = $target_model;
			$parent_path = $target_path;
		}

		return $this;
	}

	/**
	 * Finds and loads a single database record into the model.
	 * 
	 * @param   mixed  primary key
	 * @return  $this
	 */
	public function find($id = NULL)
	{
		if ($this->loaded())
		{
			throw new Kohana_Exception('Method find() cannot be called on loaded objects');
		}

		if ($id !== NULL)
		{
			$this->where($this->_primary_key, '=', $id);
		}

		return $this->_select();
	}

	/**
	 * Finds multiple database records and returns an iterator of the records found.
	 * 
	 * @return  Database_Result
	 */
	public function find_all()
	{
		return $this->_select(TRUE);
	}

	/**
	 * Count the number of records in the current table.
	 * 
	 * @return  int
	 */
	public function count_all()
	{
		return (int) DB::select(array('COUNT("*")', 'count'))
			->from($this->_table_name)
			->where_array($this->_where)
			->execute($this->_db)
			->get('count');
	}

	/**
	 * Updates or creates the record depending on loaded()
	 * 
	 * @param   array  values
	 * @return  mixed
	 */
	public function save(array $values = NULL)
	{
		return ($this->loaded()) ? $this->update($values) : $this->create($values);
	}

	/**
	 * Creates a new record
	 * 
	 * @param   array  values
	 * @return  $this
	 */
	public function create(array $values = NULL)
	{
		if (is_array($values))
		{
			$this->values($values);
		}

		list($id, $count) = DB::insert($this->_table_name)
			->columns(array_keys($this->_set))
			->values(array_values($this->_set))
			->execute($this->_db);

		if ((bool)$count)
		{
			$this->_record[$this->_primary_key] = $id;
			$this->_saved = TRUE;
		}

		return $this;
	}

	/**
	 * Updates a single record or multiple records
	 * 
	 * @param   array  values
	 * @return  $this
	 */
	public function update(array $values = NULL)
	{
		if (is_array($values))
		{
			$this->values($values);
		}

		$this->_saved = (bool) DB::update($this->_table_name)
			->set($this->_set)
			->where_array($this->_where)
			->limit($this->_limit)
			->execute($this->_db);
		
		return $this;
	}

	/**
	 * Deletes a single record or multiple records
	 * 
	 * @return  int number of rows deleted
	 */
	public function delete()
	{
		return DB::delete($this->_table_name)
			->where_array($this->_where)
			->limit($this->_limit)
			->execute($this->_db);
	}

	/**
	 * Checks whether a column value is unique.
	 * Excludes itself if loaded.
	 * 
	 * @param  string  column
	 * @param  string  value
	 * @return boolean
	 */
	public function unique($column, $value)
	{
		$count = DB::select(array('COUNT("*")', 'count'))
			->from($this->_table_name)
			->where($column, '=', $value);

		if ($this->loaded())
		{
			$count->and_where($this->primary_key(), '!=', $this->primary_val());
		}

		return (bool) $count->execute($this->_db)->get('count');
	}

	/**
	 * Adds a new relationship to between this model and another.
	 * 
	 * @param   string   alias of the has_many "through" relationship
	 * @param   ORM      related ORM model
	 * @param   array    additional data to store in "through"/pivot table
	 * @return  array    list of insert id and rows created
	 */
	public function add($alias, ORM $model, array $data = NULL)
	{
		$columns = array($this->_has_many[$alias]['foreign_key'], $this->_has_many[$alias]['far_key']);
		$values = array($this->primary_val(), $model->primary_val());

		if ($data !== NULL)
		{
			$columns = array_merge($columns, array_keys($data));
			$values = array_merge($values, array_values($data));
		}

		return DB::insert($this->_has_many[$alias]['through'])
			->columns($columns)
			->values($values)
			->execute($this->_db);
	}

	/**
	 * Tests if this object has a relationship to a different model.
	 * 
	 * @param   string   alias of the has_many "through" relationship
	 * @param   ORM      related ORM model
	 * @return  boolean
	 */
	public function has($alias, ORM $model)
	{
		return (bool) DB::select(array('COUNT("*")', 'count'))
			->from($this->_has_many[$alias]['through'])
			->where($this->_has_many[$alias]['foreign_key'], '=', $this->primary_val())
			->where($this->_has_many[$alias]['far_key'], '=', $model->primary_val())
			->execute($this->_db)
			->get('count');
	}

	/**
	 * Removes a relationship between this model and another.
	 * 
	 * @param   string   alias of the has_many "through" relationship
	 * @param   ORM      related ORM model
	 * @return  int      number of rows removed
	 */
	public function remove($alias, ORM $model)
	{
		return DB::delete($this->_has_many[$alias]['through'])
			->where($this->_has_many[$alias]['foreign_key'], '=', $this->primary_val())
			->where($this->_has_many[$alias]['far_key'], '=', $model->primary_val())
			->execute($this->_db);
	}

	/**
	 * Returns the name of the current model
	 * 
	 * @return  string
	 */
	public function model_name()
	{
		return $this->_model_name;
	}

	/**
	 * Returns the name of the current table
	 * 
	 * @return  string
	 */
	public function table_name()
	{
		return $this->_table_name;
	}

	/**
	 * Returns the primary key
	 * 
	 * @return  string
	 */
	public function primary_key()
	{
		return $this->_primary_key;
	}

	/**
	 * Returns the value of the primary key
	 * 
	 * @return  mixed
	 */
	public function primary_val()
	{
		return $this->_record[$this->_primary_key];
	}

	/**
	 * Returns the result as array
	 * 
	 * @return  array
	 */
	public function as_array()
	{
		return $this->_record;
	}

	/**
	 * The result is loaded or not
	 * 
	 * @return  boolean
	 */
	public function loaded()
	{
		return (isset($this->_record[$this->_primary_key]));
	}

	/**
	 * 
	 * @return  boolean
	 */
	public function saved()
	{
		return $this->_saved;
	}

	/**
	 * Loads the result
	 * 
	 * @return  mixed
	 */
	protected function _select($multiple = FALSE)
	{
		$this->_type = Database::SELECT;

		if ( ! empty($this->_join))
		{
			$this->select($this->_table_name.'.*');
		}

		$this->from($this->_table_name);

		if ($multiple)
		{
			return $this->as_object(get_class($this))
				->execute($this->_db);
		}

		$result = $this->limit(1)
			->execute($this->_db)
			->current();

		$this->reset();

		if ($result)
		{
			foreach ($result as $column => $value)
			{
				$this->_load_value($column, $value);
			}
		}

		return $this;
	}

	/**
	 * Load value for each column
	 * 
	 * @return $this
	 */
	protected function _load_value($column, $value)
	{
		if (strpos($column, ':') !== FALSE)
		{
			list ($model, $column) = explode(':', $column, 2);

			if ( ! isset($this->_record[$model]))
			{
				$this->_record[$model] = ORM::factory($model);
			}
			
			$this->_record[$model]->_load_value($column, $value);
		}
		else
		{
			if ($column === $this->_primary_key)
			{
				$this->where($this->_primary_key, '=', $value);
			}

			$this->_record[$column] = $value;
		}

		return $this;
	}

	/**
	 * Prepare the relations
	 * 
	 * @return void
	 */
	protected function _prepare_relations()
	{
		foreach ($this->_belongs_to as $alias => $details)
		{
			if ( ! isset($details['model']))
				$this->_belongs_to[$alias]['model'] = $alias;

			if ( ! isset($details['foreign_key']))
				$this->_belongs_to[$alias]['foreign_key'] = $alias.$this->_foreign_key_suffix;
		}

		foreach ($this->_has_one as $alias => $details)
		{
			if ( ! isset($details['model']))
				$this->_has_one[$alias]['model'] = $alias;

			if ( ! isset($details['foreign_key']))
				$this->_has_one[$alias]['foreign_key'] = $this->_model_name.$this->_foreign_key_suffix;
		}

		foreach ($this->_has_many as $alias => $details)
		{
			if ( ! isset($details['model']))
				$this->_has_many[$alias]['model'] = Inflector::singular($alias);

			if ( ! isset($details['foreign_key']))
				$this->_has_many[$alias]['foreign_key'] = $this->_model_name.$this->_foreign_key_suffix;

			if (isset($details['through']))
			{
				if ( ! isset($details['far_key']))
				{
					$this->_has_many[$alias]['far_key'] = Inflector::singular($alias).$this->_foreign_key_suffix;
				}
			}
		}
	}

} // End ORM
