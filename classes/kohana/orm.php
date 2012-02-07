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
    
    // Table
    protected $_table_name = NULL;
    protected $_table_names_plural = TRUE;
    
    // Primary key
    protected $_primary_key = 'id';
    protected $_primary_val = NULL;
    protected $_foreign_key_suffix = '_id';
    
    // Relationships
    protected $_has_one = array();
    protected $_has_many = array();
    protected $_belongs_to = array();
    
    // Values
    protected $_set = array();
    
    // Result as array
    protected $_row = array();
    
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
        elseif (isset($this->_row[$this->_primary_key]))
        {
            $this->_primary_val = $this->_row[$this->_primary_key];
            $this->where($this->_primary_key, '=', $this->_primary_val);
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
        if (isset($this->_belongs_to[$column]))
        {
            return ORM::factory(
                $this->_belongs_to[$column]['model'], 
                $this->_row[$this->_belongs_to[$column]['foreign_key']]
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
                $join_col2 = $model->table_name().'.'.$model->primary_key();
                
                $model->join($through)->on($join_col1, '=', $join_col2);
                
                $col = $through.'.'.$this->_has_many[$column]['foreign_key'];
            }
            else
            {
                $col = $this->_has_many[$column]['foreign_key'];
            }
            
            return $model->where($col, '=', $this->primary_val());
        }
        elseif (isset($this->_row[$column]))
        {
            return $this->_row[$column];
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
            $this->_row[$column] = $value;
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
            if (isset($this->_row[$column]))
            {
                $this->_row[$column] = $this->_set[$column] = $value;
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
     * Finds and loads a single database row into the model.
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
     * Finds multiple database rows and returns an iterator of the rows found.
     * 
     * @return  Database_Result
     */
    public function find_all()
    {
        return $this->_select(TRUE);
    }
    
    /**
     * Count the number of rows in the current table.
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
     * Creates a new row
     * 
     * @return  mixed  insert id or false
     */
    public function create()
    {
        list($id, $count) = DB::insert($this->_table_name)
            ->columns(array_keys($this->_set))
            ->values(array_values($this->_set))
            ->execute($this->_db);
        
        if ((bool)$count)
        {
            $this->_primary_val = $id;
        }
        else
        {
            $id = FALSE;
        }
        
        return $id;
    }
    
    /**
     * Updates a single row or multiple rows
     * 
     * @return  int  number of rows affected
     */
    public function update()
    {
        return DB::update($this->_table_name)
            ->set($this->_set)
            ->where_array($this->_where)
            ->limit($this->_limit)
            ->execute($this->_db);
    }
    
    /**
     * Deletes a single row or multiple rows
     * 
     * @return  int number of rows affected
     */
    public function delete()
    {
        return DB::delete($this->_table_name)
            ->where_array($this->_where)
            ->limit($this->_limit)
            ->execute($this->_db);
    }
    
    /**
     * Adds a new relationship to between this model and another.
     * 
     * @param   string   alias of the has_many "through" relationship
     * @param   ORM      related ORM model
     * @param   array    additional data to store in "through"/pivot table
     * @return  int
     */
    public function add($alias, ORM $model, array $data = NULL)
    {
        $columns = array($this->_has_many[$alias]['foreign_key'], $this->_has_many[$alias]['far_key']);
        $values  = array($this->primary_val(), $model->primary_val());
        
        if ($data !== NULL)
        {
            $columns = array_merge($columns, array_keys($data));
            $values  = array_merge($values, array_values($data));
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
     * @return  int
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
        return $this->_primary_val;
    }
    
    /**
     * Returns the result as array
     * 
     * @return  array
     */
    public function as_array()
    {
        return $this->_row;
    }
    
    /**
     * The result is loaded or not
     * 
     * @return  boolean
     */
    public function loaded()
    {
        return ($this->_primary_val !== NULL);
    }
    
    /**
     * Loads the result
     * 
     * @return  mixed
     */
    protected function _select($multiple = FALSE)
    {
        $this->_type = Database::SELECT;
        
        $this->from($this->_table_name);
        
        if ($multiple)
        {
            return $this->as_object(get_class($this))
                ->execute($this->_db);
        }
        
        $result = $this->limit(1)
            ->execute($this->_db)
            ->current();
        
        if ($result)
        {
            $this->_row = $result;
        }
        
        if (isset($this->_row[$this->_primary_key]))
        {
            $this->_primary_val = $this->_row[$this->_primary_key];
            $this->_where = array();
            $this->where($this->_primary_key, '=', $this->_primary_val);
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
            $defaults['model'] = $alias;
            $defaults['foreign_key'] = $alias.$this->_foreign_key_suffix;
            
            $this->_belongs_to[$alias] = array_merge($defaults, $details);
        }
        
        foreach ($this->_has_one as $alias => $details)
        {
            $defaults['model'] = $alias;
            $defaults['foreign_key'] = $this->_model_name.$this->_foreign_key_suffix;
            
            $this->_has_one[$alias] = array_merge($defaults, $details);
        }
        
        foreach ($this->_has_many as $alias => $details)
        {
            $defaults['model'] = Inflector::singular($alias);
            $defaults['foreign_key'] = $this->_model_name.$this->_foreign_key_suffix;
            $defaults['through'] = NULL;
            $defaults['far_key'] = Inflector::singular($alias).$this->_foreign_key_suffix;
            
            $this->_has_many[$alias] = array_merge($defaults, $details);
        }
    }
    
} // End ORM