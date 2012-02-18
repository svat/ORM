<?php defined('SYSPATH') or die('No direct access allowed.');
/**
 * Default auth role
 *
 * @package    Kohana/Auth
 * @author     Kohana Team
 * @copyright  (c) 2007-2009 Kohana Team
 * @license    http://kohanaphp.com/license.html
 */
class Model_Auth_Role extends ORM {

    protected $_table_columns = array(
        'id'       => array('data_type' => 'int'),
        'name'     => array('data_type' => 'string'),
        'description'    => array('data_type' => 'string'),
    );

	// Relationships
	protected $_has_many = array('users' => array('through' => 'roles_users'));

} // End Auth Role Model