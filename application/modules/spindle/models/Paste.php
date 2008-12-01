<?php
/**
 * Pastebin model
 * 
 * @package    Spindle
 * @subpackage Model
 * @license    New BSD {@link http://framework.zend.com/license/new-bsd}
 * @version    $Id: $
 */
class Spindle_Model_Paste
{
    /**
     * Table fields
     * @var array
     */
    protected $_fields;

    /**
     * Form representation/input filter of model data
     * 
     * @var Form_Paste
     */
    protected $_form;

    /**
     * @var Zend_Db_Table_Abstract
     */
    protected $_table;

    /**
     * @var array Class methods
     */
    protected $_classMethods;

    /**
     * @var My_Controller_Helper_ResourceLoader
     */
    protected $_resourceLoader;

    /**
     * Constructor
     * 
     * @param  array|Zend_Config|null $options 
     * @return void
     */
    public function __construct($options = null)
    {
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        }

        if (is_array($options)) {
            $this->setOptions($options);
        }
    }

    /**
     * Set options using setter methods
     * 
     * @param  array $options 
     * @return Spindle_Model_Paste
     */
    public function setOptions(array $options)
    {
        if (null === $this->_classMethods) {
            $this->_classMethods = get_class_methods($this);
        }
        foreach ($options as $key => $value) {
            $method = 'set' . ucfirst($key);
            if (in_array($method, $this->_classMethods)) {
                $this->$method($value);
            }
        }
        return $this;
    }

    /**
     * Set resource loader
     * 
     * @param  object $loader 
     * @return Spindle_Model_DbTable_Paste
     */
    public function setResourceLoader($loader)
    {
        if (!is_object($loader)) {
            throw new Exception('Invalid resource loader provided to ' . __CLASS__);
        }
        $this->_resourceLoader = $loader;
        return $this;
    }

    /**
     * Retrieve resource loader
     * 
     * @return object
     */
    public function getResourceLoader()
    {
        if (null === $this->_resourceLoader) {
            $this->_resourceLoader = new My_Controller_Helper_ResourceLoader;
            $this->_resourceLoader->initModule('spindle');
        }
        return $this->_resourceLoader;
    }

    /**
     * Add a paste
     * 
     * @param  array|struct $data 
     * @return string
     */
    public function add(array $data)
    {
        $form     = $this->getForm();

        $belongTo = $form->getElementsBelongTo();
        if (!empty($belongTo) && array_key_exists($belongTo, $data)) {
            $data = $data[$belongTo];
        }

        if (!$form->isValid($data)) {
            return false;
        }

        $values = $form->getValues();
        if (!empty($belongTo)) {
            $values = $values[$belongTo];
        }

        return $this->getTable()->insert($values);
    }

    /**
     * Fetch a paste by id
     *
     * Returns boolean false on failure to find the paste; otherwise, a struct 
     * is returned.
     * 
     * @param  string $id 
     * @return struct|false
     */
    public function get($id)
    {
        $table  = $this->getTable();
        $select = $table->select();
        $select->where('id = ?', $id)
               ->where('expires IS NULL OR expires = "" OR expires > ?', date('Y-m-d H:i:s'));
        $row    = $table->fetchRow($select);
        if (null == $row) {
            return false;
        }
        $data = $row->toArray();

        if (!empty($data['parent'])) {
            $parent = $this->get($data['parent']);
            if (!$parent) {
                $data['parent'] = null;
            }
        }

        $data['children'] = $this->_getChildren($id);
        return $data;
    }

    /**
     * Get list of active pastes, ordered by creation date (desc)
     * 
     * @return array
     */
    public function fetchActive(array $criteria = null)
    {
        $table   = $this->getTable();
        $adapter = $table->getAdapter();
        $select  = $adapter->select();
        $select->from('paste', array('id', 'type', 'summary', 'user', 'created', 'expires'))
               ->where('expires IS NULL OR expires = "" OR expires > ?', date('Y-m-d H:i:s'));

        if (null !== $criteria) {
            $this->_refineSelection($select, $criteria);
        } else {
            $select->order('created DESC');
        }

        return $adapter->fetchAll($select);
    }

    /**
     * Fetch count of active pastes
     * 
     * @return int
     */
    public function fetchActiveCount()
    {
        $table   = $this->getTable();
        $adapter = $table->getAdapter();
        $select  = $adapter->select();
        $select->from('paste', array('count' => 'COUNT(*)'))
               ->where('expires IS NULL OR expires = "" OR expires > ?', date('Y-m-d H:i:s'))
               ->order('created DESC');

        return $adapter->fetchOne($select);
    }

    /**
     * Retrieve form/input filter
     * 
     * @return Form_Paste
     */
    public function getForm()
    {
        if (null === $this->_form) {
            $this->_form = $this->getResourceLoader()->getForm('Paste');
        }
        return $this->_form;
    }

    /**
     * Retrieve data provider
     * 
     * @return Paste_Table
     */
    public function getTable()
    {
        if (null === $this->_table) {
            $this->_table = $this->getResourceLoader()->getDbtable('Paste');
        }
        return $this->_table;
    }

    /**
     * Retrieve paste children
     * 
     * @param  string $id 
     * @return false|array
     */
    protected function _getChildren($id)
    {
        $adapter = $this->getTable()->getAdapter();
        $select  = $adapter->select();
        $select->from('paste', array('id'))
               ->where('parent = ?', $id)
               ->where('expires IS NULL OR expires = "" OR expires > ?', date('Y-m-d H:i:s'));
        return $adapter->fetchCol($select);
    }

    /**
     * Refine the active pastes selection based on criteria provided
     *
     * Allows setting a limit to the number of records returend
     * 
     * @param  Zend_Db_Select $select 
     * @param  array $criteria 
     * @return void
     */
    protected function _refineSelection(Zend_Db_Select $select, array $criteria)
    {
        if (array_key_exists('start', $criteria) 
            && ($criteria['start'] == intval($criteria['start']))
        ) {
            if (array_key_exists('count', $criteria) 
                && ($criteria['count'] == intval($criteria['count']))
            ) {
                $select->limit($criteria['count'], $criteria['start']);
            }
        }

        $sorted = false;
        if (array_key_exists('sort', $criteria)) {
            $sort = $criteria['sort'];
            $dir  = 'ASC';
            if ('-' == substr($sort, 0, 1)) {
                $sort = substr($sort, 1);
                $dir  = 'DESC';
            }

            $fields = $this->getTable()->info('cols');
            if (in_array($sort, $fields)) {
                $select->order("$sort $dir");
                $sorted = true;
            }
        }
        if (!$sorted) {
            $select->order('created DESC');
        }
    }
}
