<?php

/**
 * Mysqli Model wrapper
 *
 * @category  Database Access
 * @package   MysqliDb
 * @author    Alexander V. Butenko <a.butenka@gmail.com>
 * @copyright Copyright (c) 2015-2017
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      http://github.com/joshcam/PHP-MySQLi-Database-Class 
 * @version   2.9-master
 *
 * @method int count ()
 * @method Model ArrayBuilder()
 * @method Model JsonBuilder()
 * @method Model ObjectBuilder()
 * @method mixed byId (string $id, mixed $fields)
 * @method mixed get (mixed $limit, mixed $fields)
 * @method mixed getOne (mixed $fields)
 * @method mixed paginate (int $page, array $fields)
 * @method Model query ($query, $numRows)
 * @method Model rawQuery ($query, $bindParams, $sanitize)
 * @method Model join (string $objectName, string $key, string $joinType, string $primaryKey)
 * @method Model with (string $objectName)
 * @method Model groupBy (string $groupByField)
 * @method Model orderBy ($orderByField, $orderbyDirection, $customFields)
 * @method Model where ($whereProp, $whereValue, $operator)
 * @method Model orWhere ($whereProp, $whereValue, $operator)
 * @method Model setQueryOption ($options)
 * @method Model setTrace ($enabled, $stripPrefix)
 * @method Model withTotalCount ()
 * @method Model startTransaction ()
 * @method Model commit ()
 * @method Model rollback ()
 * @method Model ping ()
 * @method string getLastError ()
 * @method string getLastQuery ()
 **/
class Model implements ArrayAccess
{
    /**
     * Working instance of MysqliDb created earlier
     *
     * @var MysqliDb
     */
    private $db;
    /**
     * Models path
     *
     * @var modelPath
     */
    protected static $modelPath;
    /**
     * An array that holds object data
     *
     * @var array
     */
    protected $data;
    /**
     * Flag to define is object is new or loaded from database
     *
     * @var boolean
     */
    protected $isNew = true;
    /**
     * Return type: 'Array' to return results as array, 'Object' as object
     * 'Json' as json string
     *
     * @var string
     */
    public $returnType = 'Object';
    /**
     * An array that holds has* objects which should be loaded togeather with main
     * object togeather with main object
     *
     * @var string
     */
    private $_with = array();
    /**
     * Per page limit for pagination
     *
     * @var int
     */
    public static $pageLimit = 20;
    /**
     * Variable that holds total pages count of last paginate() query
     *
     * @var int
     */
    public static $totalPages = 0;
    /**
     * An array that holds insert/update/select errors
     *
     * @var array
     */
    public $errors = null;
    /**
     * 错误信息
     * @var mixed
     */
    protected $error;
    /**
     * Primary key for an object. 'id' is a default value.
     *
     * @var stating
     */
    protected $primaryKey = 'id';
    /**
     * Table name for an object. Class name will be used by default
     *
     * @var stating
     */
    protected $dbTable;

    protected $fields = null;

    protected $limit = null;

    private $_alias = '';

    /**
     * 日期查询表达式
     * @var array
     */
    protected $timeRule = [
        'today'      => ['today', 'tomorrow'],
        'yesterday'  => ['yesterday', 'today'],
        'week'       => ['this week 00:00:00', 'next week 00:00:00'],
        'last week'  => ['last week 00:00:00', 'this week 00:00:00'],
        'month'      => ['first Day of this month 00:00:00', 'first Day of next month 00:00:00'],
        'last month' => ['first Day of last month 00:00:00', 'first Day of this month 00:00:00'],
        'year'       => ['this year 1/1', 'next year 1/1'],
        'last year'  => ['last year 1/1', 'this year 1/1'],
    ];

    /**
     * 日期查询快捷定义
     * @var array
     */
    protected $timeExp = ['d' => 'today', 'w' => 'week', 'm' => 'month', 'y' => 'year'];

    /**
     * @param array $data Data to preload on object creation
     */
    public function __construct($data = null)
    {
        $this->db = MysqliDb::getInstance();
        if (empty($this->dbTable))
            $this->dbTable = get_class($this);

        if ($data)
            $this->data = $data;
    }

    /**
     * Magic setter function
     *
     * @return mixed
     */
    public function __set($name, $value)
    {
        if (property_exists($this, 'hidden') && array_search($name, $this->hidden) !== false)
            return;

        $this->data[$name] = $value;
    }

    /**
     * Magic getter function
     *
     * @param $name Variable name
     *
     * @return mixed
     */
    public function __get($name)
    {
        if (property_exists($this, 'hidden') && array_search($name, $this->hidden) !== false)
            return null;

        if (isset($this->data[$name]) && $this->data[$name] instanceof Model)
            return $this->data[$name];

        if (property_exists($this, 'relations') && isset($this->relations[$name])) {
            $relationType = strtolower($this->relations[$name][0]);
            $modelName = ucfirst($this->relations[$name][1]);
            switch ($relationType) {
                case 'hasone':
                    $key = isset($this->relations[$name][2]) ? $this->relations[$name][2] : $name;
                    $obj = new $modelName;
                    $obj->returnType = $this->returnType;
                    return $this->data[$name] = $obj->byId($this->data[$key]);
                    break;
                case 'hasmany':
                    $key = $this->relations[$name][2];
                    $obj = new $modelName;
                    $obj->returnType = $this->returnType;
                    return $this->data[$name] = $obj->where($key, $this->data[$this->primaryKey])->get();
                    break;
                default:
                    break;
            }
        }

        if (isset($this->data[$name]))
            return $this->data[$name];

        if (property_exists($this->db, $name))
            return $this->db->$name;
    }

    public function __isset($name)
    {
        if (isset($this->data[$name]))
            return isset($this->data[$name]);

        if (property_exists($this->db, $name))
            return isset($this->db->$name);
    }

    public function __unset($name)
    {
        unset($this->data[$name]);
    }

    // ArrayAccess
    public function offsetSet($name, $value)
    {
        $this->__set($name, $value);
    }

    public function offsetExists($name)
    {
        return $this->__isset($name);
    }

    public function offsetUnset($name)
    {
        $this->__unset($name);
    }

    public function offsetGet($name)
    {
        return $this->__get($name);
    }

    /**
     * Helper function to create Model with Json return type
     *
     * @return Model
     */
    private function JsonBuilder()
    {
        $this->returnType = 'Json';
        return $this;
    }

    /**
     * Helper function to create Model with Array return type
     *
     * @return Model
     */
    private function ArrayBuilder()
    {
        $this->returnType = 'Array';
        return $this;
    }

    /**
     * Helper function to create Model with Object return type.
     * Added for consistency. Works same way as new $objname ()
     *
     * @return Model
     */
    private function ObjectBuilder()
    {
        $this->returnType = 'Object';
        return $this;
    }

    /**
     * Helper function to create a virtual table class
     *
     * @param string tableName Table name
     * @return Model
     */
    public static function table($tableName)
    {
        $tableName = preg_replace("/[^-a-z0-9_]+/i", '', $tableName);
        if (!class_exists($tableName))
            eval("class $tableName extends Model {}");
        return new $tableName();
    }

    /**
     * @return mixed insert id or false in case of failure
     */
    public function insert($data = null)
    {
        if (!is_null($data)) {
            $this->data = $data;
        }

        if (!empty($this->timestamps) && isset($this->timestamps['createTime']) && !isset($this->data[$this->timestamps['createTime']]))
            $this->data[$this->timestamps['createTime']] = time();
        if (!empty($this->timestamps) && isset($this->timestamps['updateTime']) && !isset($this->data[$this->timestamps['updateTime']]))
            $this->data[$this->timestamps['updateTime']] = time();

        $sqlData = $this->prepareData();
        if (!$this->validate($sqlData))
            return false;

        $id = $this->db->insert($this->dbTable, $sqlData);
        if ($this->db->getLastErrno() !== 0) {
            throw new Exception($this->db->getLastError(), $this->db->getLastErrno());
        }
        if (!empty($this->primaryKey) && empty($this->data[$this->primaryKey]))
            $this->data[$this->primaryKey] = $id;
        $this->isNew = false;

        return $this;
    }

    /**
     * @param array $data Optional update data to apply to the object
     */
    public function update($data = null, $numRows = null)
    {
        if ($data) {
            foreach ($data as $k => $v)
                $this->$k = $v;
        }

        if (!empty($this->timestamps) && isset($this->timestamps['updateTime']) && !isset($this->data[$this->timestamps['updateTime']]))
            $this->data[$this->timestamps['updateTime']] = time();

        $sqlData = $this->prepareData();
        if (!$this->validate($sqlData))
            return false;

        if (is_array($numRows)) {
            $this->where($numRows);
            $numRows = null;
        }
        if (!empty($this->data[$this->primaryKey])) {
            $this->db->where($this->primaryKey, $this->data[$this->primaryKey]);
        }
        $result = $this->db->update($this->dbTable . $this->processAlias(), $sqlData, $numRows);

        if ($this->db->getLastErrno() !== 0) {
            throw new Exception($this->db->getLastError(), $this->db->getLastErrno());
        }
        return $result;
    }

    /**
     * Save or Update object
     *
     * @return mixed insert id or false in case of failure
     */
    public function save($data = null)
    {
        if ($this->isNew)
            return $this->insert($data);
        return $this->update($data);
    }

    /**
     * Delete method
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public function delete()
    {
        if (!empty($this->data[$this->primaryKey])) {
            $this->db->where($this->primaryKey, $this->data[$this->primaryKey]);
        }
        $result = $this->db->delete($this->dbTable);
        if ($this->db->getLastErrno() !== 0) {
            throw new Exception($this->db->getLastError(), $this->db->getLastErrno());
        }
        return $result;
    }

    /**
     * Get object by primary key.
     *
     * @access public
     * @param $id Primary Key
     * @param array|string $fields Array or coma separated list of fields to fetch
     *
     * @return Model|array
     */
    private function byId($id, $fields = null)
    {
        $this->db->where(MysqliDb::$prefix . $this->dbTable . '.' . $this->primaryKey, $id);
        return $this->getOne($fields);
    }

    /**
     * Convinient function to fetch one object. Mostly will be togeather with where()
     *
     * @access public
     * @param array|string $fields Array or coma separated list of fields to fetch
     *
     * @return Model
     */
    protected function getOne($fields = null)
    {
        $this->processHasOneWith();
        $results = $this->db->ArrayBuilder()->getOne($this->dbTable . $this->processAlias(), $this->processField($fields));
        if ($this->db->count == 0)
            return null;

        $this->processArrays($results);
        $this->data = $results;
        $this->processAllWith($results);
        if ($this->returnType == 'Json')
            return json_encode($results);
        if ($this->returnType == 'Array')
            return $results;

        $item = new static($results);
        $item->isNew = false;

        return $item;
    }

    /**
     * Fetch all objects
     *
     * @access public
     * @param integer|array $limit Array to define SQL limit in format Array ($count, $offset)
     *                             or only $count
     * @param array|string $fields Array or coma separated list of fields to fetch
     *
     * @return array Array of Models
     */
    protected function get($limit = null, $fields = null)
    {
        $objects = array();
        $this->processHasOneWith();
        $results = $this->db->ArrayBuilder()->get($this->dbTable . $this->processAlias(), $this->processLimit($limit), $this->processField($fields));
        if ($this->db->count == 0)
            return null;

        foreach ($results as &$r) {
            $this->processArrays($r);
            $this->data = $r;
            $this->processAllWith($r, false);
            if ($this->returnType == 'Object') {
                $item = new static($r);
                $item->isNew = false;
                $objects[] = $item;
            }
        }
        $this->_with = array();
        if ($this->returnType == 'Object')
            return $objects;

        if ($this->returnType == 'Json')
            return json_encode($results);

        return $results;
    }

    /**
     * Function to set witch hasOne or hasMany objects should be loaded togeather with a main object
     *
     * @access public
     * @param string $objectName Object Name
     *
     * @return Model
     */
    private function with($objectName)
    {
        $alias = $objectName;
        if (is_array($objectName)) {
            $alias = $objectName[1];
            $objectName = $objectName[0];
        }
        if (!property_exists($this, 'relations') || !isset($this->relations[$objectName]))
            throw new Exception("No relation with name $objectName found");

        $this->_with[$objectName] = $this->relations[$objectName];

        if (!is_null($alias)) {
            $this->_with[$objectName][5] = $alias;
        }

        if (empty($this->_alias) && !empty($this->dbTable)) {
            $this->alias($this->dbTable);
        }

        return $this;
    }

    /**
     * Function to join object with another object.
     *
     * @access public
     * @param string $objectName Object Name
     * @param string $key Key for a join from primary object
     * @param string $joinType SQL join type: LEFT, RIGHT,  INNER, OUTER
     * @param string $primaryKey SQL join On Second primaryKey
     *
     * @return Model
     */
    private function withJoin($objectName, $key = null, $joinType = 'LEFT', $primaryKey = null, $alias = '')
    {
        $objectName = ucfirst($objectName);
        $joinObj = new $objectName;
        if (!$key)
            $key = $objectName . "id";

        if (!$primaryKey) {
            if (!empty($alias)) {
                $primaryKey = $alias . "." . $joinObj->primaryKey;
            } else {
                $primaryKey = MysqliDb::$prefix . $joinObj->dbTable . "." . $joinObj->primaryKey;
            }
        }

        if (!strchr($key, '.')) {
            if (!empty($this->_alias)) {
                $joinStr = $this->_alias . ".{$key} = " . $primaryKey;
            } else {
                $joinStr = MysqliDb::$prefix . $this->dbTable . ".{$key} = " . $primaryKey;
            }
        } else {
            $joinStr = MysqliDb::$prefix . "{$key} = " . $primaryKey;
        }

        $this->db->join($joinObj->dbTable . ' ' . $alias, $joinStr, $joinType);
        return $this;
    }

    /**
     * Function to get a total records count
     *
     * @return int
     */
    protected function count($field = '*')
    {
        $this->processHasOneWith();
        $res = $this->db->ArrayBuilder()->getValue($this->dbTable . $this->processAlias(), "count({$field})");
        if (!$res)
            return 0;
        return $res;
    }

    /**
     * Function to get a total records sum
     *
     * @return int
     */
    protected function sum($field)
    {
        $this->processHasOneWith();
        $res = $this->db->ArrayBuilder()->getValue($this->dbTable . $this->processAlias(), "sum({$field})");
        if (!$res)
            return 0;
        return $res;
    }

    /**
     * Pagination wraper to get()
     *
     * @access public
     * @param int $page Page number
     * @param array|string $fields Array or coma separated list of fields to fetch
     * @return array
     */
    private function paginate($page, $fields = null)
    {
        $this->db->pageLimit = self::$pageLimit;
        $res = $this->db->paginate($this->dbTable . $this->processAlias(), $page, $fields);
        self::$totalPages = $this->db->totalPages;
        if ($this->db->count == 0) return null;

        foreach ($res as &$r) {
            $this->processArrays($r);
            $this->data = $r;
            $this->processAllWith($r, false);
            if ($this->returnType == 'Object') {
                $item = new static($r);
                $item->isNew = false;
                $objects[] = $item;
            }
        }
        $this->_with = array();
        if ($this->returnType == 'Object')
            return $objects;

        if ($this->returnType == 'Json')
            return json_encode($res);

        return $res;
    }

    /**
     * Catches calls to undefined methods.
     *
     * Provides magic access to private functions of the class and native public mysqlidb functions
     *
     * @param string $method
     * @param mixed $arg
     *
     * @return mixed
     */
    public function __call($method, $arg)
    {
        if (method_exists($this, $method))
            return call_user_func_array(array($this, $method), $arg);

        call_user_func_array(array($this->db, $method), $arg);
        return $this;
    }

    /**
     * Catches calls to undefined static methods.
     *
     * Transparently creating Model class to provide smooth API like name::get() name::orderBy()->get()
     *
     * @param string $method
     * @param mixed $arg
     *
     * @return mixed
     */
    public static function __callStatic($method, $arg)
    {
        $obj = new static;
        $result = call_user_func_array(array($obj, $method), $arg);
        if (method_exists($obj, $method))
            return $result;
        return $obj;
    }

    /**
     * Converts object data to an associative array.
     *
     * @return array Converted data
     */
    public function toArray()
    {
        $data = $this->data;
        $this->processAllWith($data);
        foreach ($data as &$d) {
            if ($d instanceof Model)
                $d = $d->data;
        }
        return $data;
    }

    /**
     * Converts object data to a JSON string.
     *
     * @return string Converted data
     */
    public function toJson()
    {
        return json_encode($this->toArray());
    }

    /**
     * Converts object data to a JSON string.
     *
     * @return string Converted data
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * Function queries hasMany relations if needed and also converts hasOne object names
     *
     * @param array $data
     */
    private function processAllWith(&$data, $shouldReset = true)
    {
        if (count($this->_with) == 0)
            return;

        foreach ($this->_with as $name => $opts) {
            $relationType = strtolower($opts[0]);
            $modelName = ucfirst($opts[1]);
            if ($relationType == 'hasone') {
                $obj = new $modelName;
                $table = isset($opts[5]) && $opts[5] ? $opts[5] : $obj->dbTable;
                $primaryKey = isset($opts[4]) && $opts[4] ? $opts[4] : $obj->primaryKey;

                if (!isset($data[$table])) {
                    $data[$name] = $this->$name;
                    continue;
                }
                // if ($data[$table][$primaryKey] === null) {
                //     $data[$name] = null;
                // } else {
                if ($this->returnType == 'Object') {
                    $item = new $modelName($data[$table]);
                    $item->returnType = $this->returnType;
                    $item->isNew = false;
                    $data[$name] = $item;
                } else {
                    $data[$name] = $data[$table];
                }
                //}
                if ($table != $name) {
                    unset($data[$table]);
                }
            } else
                $data[$name] = $this->$name;
        }
        if ($shouldReset)
            $this->_with = array();
    }

    /*
     * Function building hasOne joins for get/getOne method
     */
    private function processHasOneWith()
    {
        if (count($this->_with) == 0)
            return;
        foreach ($this->_with as $name => $opts) {
            $relationType = strtolower($opts[0]);
            $modelName = $opts[1];
            $key = null;
            $joinType = 'LEFT';
            $primaryKey = null;
            $alias = '';

            if (isset($opts[2])) {
                $key = $opts[2];
            }
            if (isset($opts[3])) {
                $joinType = $opts[3];
            }
            if (isset($opts[4])) {
                $primaryKey = $opts[4];
            }
            if (isset($opts[5])) {
                $alias = $opts[5];
            }
            if ($relationType == 'hasone') {
                $this->db->setQueryOption("MYSQLI_NESTJOIN");
                $this->withJoin($modelName, $key, $joinType, $primaryKey, $alias);
            }
        }
    }

    /**
     * @param array $data
     */
    private function processArrays(&$data)
    {
        if (isset($this->jsonFields) && is_array($this->jsonFields)) {
            foreach ($this->jsonFields as $key)
                $data[$key] = json_decode($data[$key]);
        }

        if (isset($this->arrayFields) && is_array($this->arrayFields)) {
            foreach ($this->arrayFields as $key)
                $data[$key] = explode("|", $data[$key]);
        }
    }

    /**
     * @param array $data
     */
    private function validate($data)
    {
        if (!$this->dbFields)
            return true;

        foreach ($this->dbFields as $key => $desc) {
            $type = null;
            $required = false;
            if (isset($data[$key]))
                $value = $data[$key];
            else
                $value = null;

            if (is_array($value))
                continue;

            if (isset($desc[0]))
                $type = $desc[0];
            if (isset($desc[1]) && ($desc[1] == 'required'))
                $required = true;

            if ($required && strlen($value) == 0) {
                $this->errors[] = array($this->dbTable . "." . $key => "is required");
                continue;
            }
            if ($value == null)
                continue;

            switch ($type) {
                case "text":
                    $regexp = null;
                    break;
                case "int":
                    $regexp = "/^[0-9]*$/";
                    break;
                case "double":
                    $regexp = "/^[0-9\.]*$/";
                    break;
                case "bool":
                    $regexp = '/^(yes|no|0|1|true|false)$/i';
                    break;
                case "datetime":
                    $regexp = "/^[0-9a-zA-Z -:]*$/";
                    break;
                default:
                    $regexp = $type;
                    break;
            }
            if (!$regexp)
                continue;

            if (!preg_match($regexp, $value)) {
                $this->errors[] = array($this->dbTable . "." . $key => "$type validation failed");
                continue;
            }
        }
        return !count($this->errors) > 0;
    }

    private function prepareData()
    {
        $this->errors = array();
        $sqlData = array();
        if (count($this->data) == 0)
            return array();

        if (method_exists($this, "preLoad"))
            $this->preLoad($this->data);

        if (!$this->dbFields)
            return $this->data;

        foreach ($this->data as $key => &$value) {
            if ($value instanceof Model && $value->isNew == true) {
                $id = $value->save();
                if ($id)
                    $value = $id;
                else
                    $this->errors = array_merge($this->errors, $value->errors);
            }

            if (!in_array($key, array_keys($this->dbFields)))
                continue;

            if (!is_array($value)) {
                $sqlData[$key] = $value;
                continue;
            }

            if (isset($this->jsonFields) && in_array($key, $this->jsonFields))
                $sqlData[$key] = json_encode($value);
            else if (isset($this->arrayFields) && in_array($key, $this->arrayFields))
                $sqlData[$key] = implode("|", $value);
            else
                $sqlData[$key] = $value;
        }
        return $sqlData;
    }

    public function insertGetId($data)
    {
        $this->insert($data);
        return !empty($this->primaryKey) ? $this->data[$this->primaryKey] : $this->db->getInsertId();
    }

    /**
     * A convenient SELECT COLUMN function to get a single column value from one row
     *
     * @param string  $column    The desired column 
     * @param int     $limit     Limit of rows to select. Use null for unlimited..1 by default
     *
     * @return mixed Contains the value of a returned column / array of values
     */
    public function getValue($column = null, $limit = 1)
    {
        $res = $this->ArrayBuilder()->get($limit, $this->processField($column) . " AS retval");
        unset($this->data['retval']); //去除保存在data中的retval

        if (!$res) {
            return null;
        }

        if ($limit == 1) {
            if (isset($res[0]["retval"])) {
                return $res[0]["retval"];
            }
            return null;
        }

        $newRes = array();
        for ($i = 0; $i < $this->count; $i++) {
            $newRes[] = $res[$i]['retval'];
        }
        return $newRes;
    }

    /**
     * FOR UPDATE
     *
     * @param boolean $lock
     * @return void
     */
    public function lock($lock = true)
    {
        if ($lock === true) {
            return $this->setQueryOption('FOR UPDATE');
        }
        return $this;
    }

    /**
     * where增强版
     *
     * @param string|array $whereProp
     * @param string $whereValue
     * @param string $operator
     * @param string $cond
     * @return void
     */
    public function where($whereProp, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND')
    {
        if (is_array($whereProp)) {
            foreach ($whereProp as $vo) {
                $this->db->where(...$vo);
            }
        } else {
            $this->db->where($whereProp, $whereValue, $operator, $cond);
        }
        return $this;
    }

    public function whereOr($whereProp, $whereValue = 'DBNULL', $operator = '=')
    {
        return $this->orWhere($whereProp, $whereValue, $operator);
    }

    /**
     * 查询日期或者时间
     * 
     * @access public
     * @param  string       $field 日期字段名
     * @param  string|array $op    比较运算符或者表达式
     * @param  string|array $range 比较范围
     * @param  string       $logic AND OR
     * @return $this
     */
    public function whereTime($field, $op, $range = null, $logic = 'AND')
    {
        if (is_null($range)) {
            if (is_array($op)) {
                $range = $op;
            } else {
                if (isset($this->timeExp[strtolower($op)])) {
                    $op = $this->timeExp[strtolower($op)];
                }

                if (isset($this->timeRule[strtolower($op)])) {
                    $range = $this->timeRule[strtolower($op)];
                } else {
                    $range = $op;
                }
            }

            $op = is_array($range) ? 'between' : '>=';
        }

        if (is_array($range)) {
            return $this->where($field, [strtotime($range[0]), strtotime($range[1])], strtolower($op), $logic);
        }
        return $this->where($field, strtotime($range), strtolower($op), $logic);
    }

    public function field($field = null)
    {
        if (is_string($field)) {
            if (preg_match('/[\<\'\"\(]/', $field)) {
                $this->fields = $field;
                return $this;
            }
            $field = array_map('trim', explode(',', $field));
        }
        $fields = [];
        foreach ($field as $key => $vo) {
            if (is_numeric($key)) {
                $fields[] = $vo;
            } else {
                $fields[] = $key . ' ' . $vo;
            }
        }
        $this->fields = $fields;
        return $this;
    }

    public function select($limit = null, $fields = null)
    {
        return $this->get($limit, $fields);
    }

    public function find($fields = null)
    {
        return $this->getOne($fields);
    }

    public function value($field = null)
    {
        return $this->getValue($field, 1);
    }

    public function column($field = null)
    {
        return $this->getValue($field, null);
    }

    protected function processAlias()
    {
        $alias = !empty($this->_alias) ? ' ' . $this->_alias : '';
        $this->_alias = '';
        return $alias;
    }

    protected function processField($fields = null)
    {
        if (is_null($fields) && !is_null($this->fields)) {
            $fields = $this->fields;
        }
        $this->fields = null;
        return $fields;
    }

    protected function processLimit($limit = null)
    {
        if (is_null($limit) && !is_null($this->limit)) {
            $limit = $this->limit;
        }
        $this->limit = null;
        return $limit;
    }

    public function alias($alias)
    {
        $this->_alias = $alias;
        return $this;
    }

    /**
     * 设置数据
     * 
     * @param  mixed $field 字段名或者数据
     * @param  mixed $value 字段值
     * @return $this
     */
    public function data($field, $value = null)
    {
        if (is_array($field)) {
            $this->data = array_merge($this->data, $field);
        } else {
            $this->data[$field] = $value;
        }

        return $this;
    }

    public function isUpdate($update = true)
    {
        $this->isNew = !$update;
    }

    public function groupBy($groupByField)
    {
        if (empty($groupByField)) {
            return $this;
        }
        if (is_array($groupByField)) {
            foreach ($groupByField as $vo) {
                $this->db->groupBy(...$vo);
            }
        } else {
            $this->db->groupBy($groupByField);
        }
        return $this;
    }

    public function group($groupByField)
    {
        return $this->groupBy($groupByField);
    }

    public function order($orderByField, $orderbyDirection = "DESC", $customFieldsOrRegExp = null)
    {
        if (empty($orderByField)) {
            return $this;
        }
        if (is_array($orderByField)) {
            foreach ($orderByField as $vo) {
                $this->db->orderBy(...$vo);
            }
        } else {
            if (strpos($orderByField, ' ')) {
                list($orderByField, $orderbyDirection) = explode(' ', $orderByField);
            }
            $this->db->orderBy($orderByField, $orderbyDirection, $customFieldsOrRegExp);
        }
        return $this;
    }

    public function limit($offset, $length = null)
    {
        if (is_null($length) && strpos($offset, ',')) {
            list($offset, $length) = explode(',', $offset);
        }
        $offset = intval($offset);
        if ($length) {
            $this->limit = [$offset, intval($length)];
        } else {
            $this->limit = $offset;
        }
        return $this;
    }

    public function dbLock($table)
    {
        $this->db->lock($table);
        return $this;
    }

    /**
     * 兼容TP风格
     *
     * @return void
     */
    public function startTrans()
    {
        return $this->startTransaction();
    }

    /**
     * 获取最后次执行的sql语句
     *
     * @return void
     */
    public function getLastSql()
    {
        return Common::dbi()->getLastQuery();
    }

    /**
     * 添加一条数据
     *
     * @param array $data
     * @return void
     */
    public function create($data)
    {
        return $this->insert($data);
    }

    /**
     * 静态调用where
     *
     * @return void
     */
    public static function __where(...$arg)
    {
        $obj = new static;
        return call_user_func_array(array($obj, 'where'), $arg);
    }

    /**
     * 静态调用create
     *
     * @param array $data
     * @return void
     */
    public static function __create($data)
    {
        $obj = new static;
        return $obj->insert($data);
    }

    /**
     * 指定分页
     * 
     * @param  mixed $page     页数
     * @param  mixed $listRows 每页数量
     * @return $this
     */
    public function page($page, $listRows = null)
    {
        if (is_null($listRows) && strpos($page, ',')) {
            list($page, $listRows) = explode(',', $page);
        }
        if ($page < 1) {
            $page = 1;
        }
        if ($listRows < 1) {
            $listRows = 10;
        }
        return $this->limit(($page - 1) * $listRows, $listRows);
    }

    public function getData($field = null)
    {
        if (is_null($field)) {
            return $this->data;
        }
        return isset($this->data[$field]) ? $this->data[$field] : null;
    }

    public function join($joinTable, $joinCondition = '', $joinType = '')
    {
        if (empty($joinTable)) {
            return $this;
        }
        if (is_array($joinTable)) {
            foreach ($joinTable as $vo) {
                $this->db->join(...$vo);
            }
        } else {
            $this->db->join($joinTable, $joinCondition, $joinType);
        }
        return $this;
    }

    public function distinct($distinct = true)
    {
        if ($distinct === true) {
            $this->db->setQueryOption('DISTINCT');
        }
        return $this;
    }

    public function getError()
    {
        return $this->error;
    }
}
