<?php defined('SYSPATH') or die('No direct script access.');

abstract class Database_Query_Builder_Where extends Kohana_Database_Query_Builder_Where {
    
    /**
     * Sets the conditions from an array
     * 
     * @param   array  conditions
     * @return  $this
     */
    public function where_array(array $conditions)
    {
        $this->_where = $conditions;
        
        return $this;
    }
}