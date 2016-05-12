<?php

/**
 *
 */
class ObjectProperty
{

  public $name, $permission, $type, $column, $class, $nullable = true;

  static function init(ReflectionProperty $property)
  {
    $p = new ObjectProperty;
    $p->name = $property->getName();
    $comment = $property->getDocComment();
    if($p->permission = self::getDeclaration($comment, 'property', '(readwrite|readonly|creatable|protected)')){
      $p->class = self::getDeclaration($comment, 'class', '[A-Za-z0-9_]+');
      $p->column = self::getDeclaration($comment, 'column', '[A-Za-z0-9_]+');
      $p->nullable = self::getDeclaration($comment, 'nullable', '[A-Za-z0-9_]+') === 'false' ? false : true;
      $p->type = self::getDeclaration($comment, 'type', '[A-Za-z0-9_]+');
      return $p;
    }else{
      return null;
    }
  }

  static function getDeclaration($comment, $param, $regex)
  {
    $matches = array();
    if(preg_match("/@{$param} $regex/", $comment, $matches)){
      return str_replace("@{$param} ", '',$matches[0]);
    }
    return null;
  }

}


abstract class Object extends stdClass
{

  protected $id;
  private $privateData;

  const cacheDuration = 5, db = null, table = null, readonly = false, super = null, prefix = null, endpoint = null, local = false, documentation = true;

  static $endpoints = array(), $lists = array(), $properties = array(), $writable = array(), $readonly = array(), $creatable = array(), $objectProperties = array(), $actions = array(), $info = array();

  /**
   * Contruct object from array
   * @param type $dictionary
   */
  function __construct($id = null)
  {
    if (is_string($id)) {
      $class = get_called_class();
      $info = $class::getWithId($id);
      $this->id = $id;
      $this->load($info);
    }
  }

  function __toString()
  {
    return $this->id;
  }

  /**
   * Schema where the object resides
   * @return type
   */
  static function schema()
  {
    $class = get_called_class();
    $db = $class::db;
    $table = $class::table;
    return "{$db}.{$table}";
  }

  static function endpoint()
  {
    $class = get_called_class();
    if($class::endpoint)
      return $class::endpoint;
    $endpoint = lcfirst(str_replace($class::prefix,'',$class));
    $dash = text($endpoint)->camelToDashes();
    return Inflect::pluralize($dash);
  }

  static function buildDocumentation()
  {
    $class = get_called_class();

    if($class::documentation){
      $endpoint = $class::endpoint();

      $d = new APIDocumentation;
      $d->group = str_replace($class::prefix,'',$class);
      $d->version = '1.0.0';
      $d->addEndpoint('GET/POST/PUT/DELETE',"{$endpoint}/:id",'','');
      //$d->addEndpoint('POST',"{$endpoint}",'Create','CreateObject');
      //$d->addEndpoint('PUT',"{$endpoint}/:id",'Save','SaveObject');
      //$d->addEndpoint('DELETE',"{$endpoint}/:id",'Delete','DeleteObject');

      $lists = $class::$lists;
      foreach($lists as $e => $l){
        $name = str_replace($class,'',$l);
        $d->addEndpoint('GET',"{$endpoint}/:id/{$e}",$name,$name);
      }

      $d->save();
    }
  }

  /**
   * Initialize Object with Id
   * @param type $id
   * @return null
   */
  static function withId($id)
  {
    if (!strlen($id))
      return null;
    $class = get_called_class();
    if ($class::local) {
      $object = $class::localWithId($id);
      if ($object)
        return $object;
    }
    $result = $class::getWithId($id);
    if ($result)
      $object = $class::init($result);
    if ($class::local) {
      $cacheId = $class::memcachedId($id);
      global $localObjectCache;
      $localObjectCache[$cacheId] = $object;
    }
    return $object->id ? $object : null;
  }

  static function withIds($ids)
  {
    $class = get_called_class();
    if(!is_array($ids))
      $ids = explode(',',$ids);
    $objects = array();
    foreach ($ids as $id) {
      $object = $class::withId($id);
      if($object)
        array_push($objects, $object);
    }
    return $objects;
  }

  static function serialize($data)
  {
    return json_encode(self::encode($data));
  }

  static function deserialize($json)
  {
    if(is_string($json))
      $json = json_decode($json, true);
    return self::decode($json);
  }

  static function encode($data)
  {
    if(is_array($data)){
      $a = array();
      foreach ($data as $key => $value) {
        $a[$key] = self::encode($value);
      }
      return $a;
    }else if(is_object($data)){
      if(is_subclass_of($data,Object)){
        return array(
          'id' => $data->id,
          '_class' => get_class($data)
        );
      }
      return $data;
    }else{
      return $data;
    }
  }

  static function decode($data)
  {
    if(is_array($data)){
      if($data['_class'] && $data['id']){
        $class = $data['_class'];
        if(class_exists($class)){
          return $class::withId($data['id']);
        }else{
          return $data;
        }
      }else{
        $a = array();
        foreach ($data as $key => $value) {
          $a[$key] = self::decode($value);
        }
        return $a;
      }
    }else{
      return $data;
    }
  }

  private static function localWithId($id)
  {
    $class = get_called_class();
    $cacheId = $class::memcachedId($id);

    global $localObjectCache;
    $data = $localObjectCache[$cacheId];
    if ($data) {
      makelog("FETCHED GLOBAL OBJECT: $class($id)");
      return $data;
    }
    return null;
  }

  static function memcachedId($id)
  {
    $id = mysqlRealEscString($id);
    $class = get_called_class();
    $primary = $class::wherePrimaryKey($id);
    $cacheId = md5("$class::$primary");
    return $cacheId;
  }

  /**
   * Get and object with and id, first from local cache, then memcache, then from the db.s
   * @global type $localCache
   * @param type $id
   * @return null
   */
  private static function getWithId($id)
  {
    if (!strlen($id))
      return null;
    $id = mysqlRealEscString($id);
    $class = get_called_class();
    $primary = $class::wherePrimaryKey($id);
    if ($primary) {
      $cacheId = $class::memcachedId($id);
      $schema = $class::schema();
      $query = "SELECT * FROM {$schema} WHERE $primary LIMIT 1";

      global $localCache;
      $data = $localCache[$cacheId];
      if ($data) {
        makelog("FROM LOCAL CACHE: $query");
        return $data;
      }

      if (!$data && $class::cacheDuration) {
        try {
          $data = memcached()->get($cacheId);
        } catch (Exception $exc) {
          echo $exc->getTraceAsString();
        }
        if ($data) {
          makelog("FROM MEMCACHE: $query");
          $localCache[$cacheId] = $data;
          return $data;
        }
      }

      $result = sql()->get($query);
      memcached()->set($cacheId, $result, $class::cacheDuration);
      $localCache[$cacheId] = $result;
      return $result;
    } else {
      return null;
    }
    $object = self::withKeys($class::keys($id));
    return $object->id ? $object : null;
  }

  /**
   * Primary key statement
   * @param type $id
   * @return null
   */
  private static function wherePrimaryKey($id)
  {
    $class = get_called_class();
    $keys = $class::keys($id);
    $comps = array();
    foreach ($keys as $key => $val) {
      $val = mysqlRealEscString($val);
      $v = "$key = '$val'";
      array_push($comps, $v);
    }
    if (count($comps)) {
      $primary = join(' AND ', $comps);
      return $primary;
    }
    return null;
  }

  /**
   * Get an object
   * @param type $query
   * @param type $cacheId
   * @param type $timeout
   * @return type
   */
  static function get($query, $timeout = 0, $cacheId = null)
  {
    $class = get_called_class();
    return MF5mySQL()->get($query, "{$class}::init", $timeout);
  }

  /**
   * Fetch a list of objects
   * @param type $query
   * @param type $cacheId
   * @param type $timeout
   * @return type
   */
  static function fetch($query, $timeout = 0, $cacheId = null)
  {
    $class = get_called_class();
    return MF5mySQL()->fetch($query, "{$class}::init", $timeout);
  }

  /**
   * Get a property of the object
   */
  static function getProperty($name)
  {
    $class = get_called_class();
    $reflector = new ReflectionClass($class);
    try {
      $prop = $reflector->getProperty($name);
    } catch (Exception $e) {
      try {
        $prop = $reflector->getProperty("{$name}Id");
      } catch (Exception $e) {
        return null;
      }
    }
    return ObjectProperty::init($prop);
  }

  /**
   * Get the properties of the object
   */
  static function getProperties($type = null)
  {
    global $classProperties;
    $properties = array();
    $class = get_called_class();
    $t = $type ? join(',',$type) : 'all';
    if(!$classProperties['v2'][$class][$t]){
      $reflector = new ReflectionClass($class);
      $props = $reflector->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);
      foreach ($props as $prop) {
        $property = ObjectProperty::init($prop);
        if($property){
          if($type === null || ($type == $property->permission) || (in_array($property->permission, $type)))
            array_push($properties, $property);
        }
      }
      $classProperties['v2'][$class][$t] = $properties;
      return $properties;
    }else{
      return $classProperties['v2'][$class][$t];
    }
  }

  /**
   * Collect the readonly properties
   * @return type
   */
  static function readonlyProperties()
  {
    $class = get_called_class();
    $properties = $class::$properties;
    if(count($properties)){
      $props = array_merge($class::$writable, $class::$readonly);
      return self::columnProperties($properties, $props);
    }else{
      $properties = array();
      $props = self::getProperties(array('protected','readwrite'));
      foreach($props as $prop){
        $properties[$prop->column ? $prop->column : $prop->name] = $prop->name;
      }
      return $properties;
    }
  }

  /**
   * Collect the writable properties/columns for an object
   * @return type
   */
  static function writableProperties()
  {
    $class = get_called_class();
    $properties = $class::$properties;
    if(count($properties)){
      $writable = $class::$writable;
      return self::columnProperties($properties, $writable);
    }else{
      $properties = array();
      $props = self::getProperties(array('readwrite'));
      foreach($props as $prop){
        $properties[$prop->column ? $prop->column : $prop->name] = $prop->name;
      }
      return $properties;
    }
  }

  /**
   * Collect the properties that are allowed on creation
   * @return type
   */
  static function creatableProperties()
  {
    $class = get_called_class();
    $properties = $class::$properties;
    if(count($properties)){
      $props = array_merge(array_merge($class::$writable, $class::$readonly), $class::$creatable);
      return $class::columnProperties($properties, $props);
    }else{
      $properties = array();
      $props = self::getProperties(array('readwrite','protected','creatable'));
      foreach($props as $prop){
        $key = $prop->column ? $prop->column : $prop->name;
        $properties[$key] = $prop->name;
      }
      return $properties;
    }
  }

  static function storableProperties()
  {
    $class = get_called_class();
    $properties = $class::$properties;
    if(count($properties)){
      return $properties;
    }else{
      $properties = array();
      $props = self::getProperties(array('readwrite','protected','creatable'));
      foreach($props as $prop){
        $key = $prop->column ? $prop->column : $prop->name;
        $properties[$key] = $prop->name;
      }
      return $properties;
    }
  }

  static function allProperties()
  {
    $class = get_called_class();
    $properties = $class::$properties;
    if(count($properties)){
      return $properties;
    }else{
      $properties = array();
      $props = self::getProperties();
      foreach($props as $prop){
        $properties[$prop->column ? $prop->column : $prop->name] = $prop->name;
      }
      return $properties;
    }
  }

  /**
   * Match properties to columns
   * @param type $properties
   * @param type $columns
   * @return type
   */
  static function columnProperties($properties, $columns)
  {
    $params = array();
    foreach ($columns as $column) {
      if ($properties[$column])
        $params[$column] = $properties[$column];
    }
    return $params;
  }

  /**
   * Build a list of the writable properties and values to the database for an object
   * @param type $object
   * @param type $writableProperties
   * @return type
   */
  static function buildSavable($object)
  {
    $class = get_called_class();
    $writableProperties = $class::readonlyProperties();
    return $class::build($object, $writableProperties);
  }

  static function buildCreatable($object)
  {
    $class = get_called_class();
    $creatableProps = $class::creatableProperties();
    return $class::build($object, $creatableProps);
  }

  static function buildStorable($object)
  {
    $class = get_called_class();
    $props = $class::storableProperties();
    return self::build($object, $props);
  }

  /**
   * Build a list of properties for insert or updating the db
   * @param type $object
   * @param type $properties
   * @return type
   */
  static function build($object, $properties)
  {
    $output = array();
    foreach ($properties as $column => $property) {
      $prop = self::getProperty($property);
      if (!($prop->nullable === false && $object->$property === null)){
        if (is_array($object->$property)){
          $output[$column] = self::serialize($object->$property);
        } else{
          $output[$column] = $object->$property;
        }
      }
    }
    return $output;
  }

  /**
   * Save the object
   * @param type $input
   * @return boolean
   * @throws Exception
   * @throws type
   */
  public function save($input = array())
  {
    $class = get_called_class();
    makelog("SAVING $class");
    if ($class::readonly)
      return false;
    $primary = $class::wherePrimaryKey($this->id);
    if ($this->authorized() && $primary) {
      $writableProperties = $class::writableProperties();
      $properties = self::generateInput($input, array_flip($writableProperties)); //array_intersect_key($input, array_flip($writableProperties));
      $this->load($properties);
      // Get Properties of object to save based on what we are allowed to write and what the user input
      if ($this->validate()) {
        $this->beforeSave();
        $savable = $class::buildSavable($this);
//        makelog(json_encode($savable));
        // At some point we will probably want to track all object saves
        //$this->trackHistory($savable);
        sql()->update($class::schema(), $savable, "WHERE $primary");
        $cacheId = $class::memcachedId($this->id);
        memcached()->delete($cacheId);
        $this->afterSave();
        return true;
      }
      return false;
    } else {
      if (!$primary) {
        throw new Exception('The object identifier is invalid', 102);
      } else {
        throw MF5Exception::notAuthorized();
      }
    }
    return false;
  }

  // At some point we will probably want to track all object saves
  function trackHistory($input, $options = array())
  {
    $class = get_class($this);
    $options = $class::options($options);
    $insert['objectId'] = $this->id;
    $insert['className'] = get_class($this);
    $insert['previousData'] = $this->assoc($options);
    $insert['updatedData'] = $input;
    $insert['editUserId'] = session()->user->id;
    sql()->insert('tracking.objectHistory',$insert);
  }

  /**
   * Called right before saving
   */
  function beforeSave()
  {

  }

  /**
   * Called imediately after a successful save
   */
  function afterSave()
  {

  }

  function delete()
  {
    // Must subclass to perform action
    $this->deleteCache();
    return true;
  }

  function deleteCache()
  {
    // Must subclass to perform action
    $class = get_called_class();
    $cacheId = $class::memcachedId($this->id);
    memcached()->delete($cacheId);
  }

  static function create2($input = array())
  {
    $class = get_called_class();
    return $class::create($input);
  }

  static function generateInput($input, $properties = array())
  {
    $output = array_intersect_key($input, $properties);
    foreach ($properties as $key => $value) {
      if(!$output[$key]){
        if(preg_match('/Id$/', $key)){
          $k = preg_replace('/Id$/', '', $key);

          if(isset($input[$k])){
            if(is_object($input[$k]))
              $output[$key] = $input[$k]->id;
            else if (is_array($input[$k]) && $input[$k]['id']) {
              $output[$key] = $input[$k]['id'];
            } else
              $output[$key] = $input[$k];
          }
        }
      }
    }
    return $output;
  }

  /**
   * Create a new object
   * @param type $input
   * @return \class|null
   */
  static function create($input = array(), $update = false, $object = null)
  {
    $class = get_called_class();
    if ($class::readonly)
      return null;
    $writableProperties = $class::creatableProperties();
    $properties = self::generateInput($input, array_flip($writableProperties)); //array_intersect_key($input, array_flip($writableProperties));
    $object = $class::init($properties, $object);
    if ($object->validate()) {
      $object->beforeCreation();
      $object->beforeSave();
      $creatable = $class::buildCreatable($object);
      // Create
      $schema = $class::schema();
      $id = $update ? sql()->insert_update($schema, $creatable, array_keys($creatable)) : sql()->insert($schema, $creatable);
      if($id)
        $object->id = $id;
      $object->afterCreation();
      $object->afterSave();
      $object->deleteCache();
      return $object;
    }
    return null;
  }

  function beforeCreation()
  {

  }

  function afterCreation()
  {

  }

  /**
   * Validate the object, throw exception if invalid
   * @return boolean
   * @throws type
   */
  function validate()
  {
    return true;
  }

  /**
   * Authorization for editing object
   * @return boolean
   */
  function authorized()
  {
    return true;
  }

  /**
   * Instantiate with keys
   * @param type $keys
   * @return null
   */
  static function withKeys($keys)
  {
    $class = get_called_class();
    $info = $class::getWithKeys($keys);
    $object = $class::init($info);
    if ($info)
      return $object;
    else
      return null;
  }

  /**
   * Primary/Unique Keys of object
   * @param type $id
   * @return type
   */
  static function keys($id)
  {
    return array('id' => $id);
  }

  static function run($method, $params)
  {
    return runClassMethod(get_called_class(), $method, $params);
  }

  /**
   * Similar to save, but this will force an input, where save requires the object to exist
   * @return boolean
   */
  function store()
  {
    $class = get_called_class();
    $cacheId = $class::memcachedId($this->id);
    memcached()->delete($cacheId);
    $this->beforeSave();
    $storable = $class::buildStorable($this);
    sql()->insert_update($class::schema(), $storable, array_keys($storable));
    $this->afterSave();
    return true;
  }

  /**
   * Reload an object
   * @return type
   */
  function reload()
  {
    // Fetch from db, recache
    $class = get_called_class();
    $info = $class::getWithId($this->id);
    $this->load($info);
    return $info ? true : false;
  }

  /**
   * Load an objects properties
   * @param type $dictionary
   * @return type
   */
  function load($dictionary)
  {
    if (!$dictionary)
      return;
    foreach ($dictionary as $key => $value) {
      $key = self::property($key);
      $prop = self::getProperty($key);
      $k = $prop->name;
      if(!$k)
        $k = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
      if($prop->type == 'json')
        $value = Object::deserialize($value);
      if ($k) {
        $this->{$k} = $value;
      }
    }
  }

  function loadFromData($data = array())
  {
    $class = get_class($this);
    if (is_assoc($data))
      $data = array_intersect_key($data, $class::allProperties());
    $this->load($data);
  }

  static function fromData($data = array())
  {
    $class = get_called_class();
    $object = new $class;
    if (is_assoc($data))
      camelCaseKeys($data);
    $object->loadFromData($data);
    return $object;
  }

  /**
   * The associated property with a key
   * @param type $key
   * @return type
   */
  private static function property($key)
  {
    $class = get_called_class();
    $properties = $class::allProperties();
    $property = $properties[$key];
    if (!$property)
      $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    return $property ? $property : $key;
  }

  /**
   * Initialize and object
   * @param type $dictionary
   * @return \class
   */
  static function init($dictionary = null, $object = null)
  {
    $class = get_called_class();
    $object = $object ? new $class($object) : new $class;
    $object->load($dictionary);
    return $object;
  }

  /**
   * Initialize list of dictionaries to objects
   * @param array $array
   * @return array
   */
  static function initList($array)
  {
    $class = get_called_class();
    return array_map("{$class}::init", $array);
  }

  /**
   * Auto generate methods for list calls
   */
  function __call($method, $args = array())
  {
    $class = get_called_class();
    $className = $class.ucwords($method);
    if (class_exists($className)){
      $object = lcfirst(str_replace($class::prefix,'',$class));
      if(is_array($args[0]))
        $args = $args[0];
      $args[$object] = $this;
      return $className::run('find',$args);
    }

    $parentClass = get_parent_class($this);
    $className = $parentClass.ucwords($method);
    if (class_exists($className)) {
      $object = lcfirst(str_replace($parentClass::prefix,'',$parentClass));
      if(is_array($args[0]))
        $args = $args[0];
      $args[$object] = $this;
      return $className::run('find',$args);
    }

    return null;
  }

  /**
   * Getter
   * @param type $name
   * @return type
   */
  function __get($name)
  {
    $class = get_called_class();

    if ($name == 'assoc' || $name == 'store' || $name == 'elastic' || $name == 'elasticsearch' || $name == 'mongo' || in_array($name, $class::$actions))
      return $this->$name();

    if (preg_match('/^_/', $name)) {
      $name = preg_replace('/^_/', '', $name);
      $local = true;
    } else {
      $local = false;
    }

    $objects = $class::$objectProperties;
    $property = $name . 'Id';

    if ($this->privateData[$name])
      return $this->privateData[$name];

    if($prop = self::getProperty($property)){
      if(($declaredClass = $prop->class)){
        return $this->privateData[$name] ? $this->privateData[$name] : ($local ? null : $this->privateData[$name] = $declaredClass::withId($this->{$name . 'Id'}));
      }
    }

    if ($objects[$name]) {
      $className = $objects[$name];
      if (class_exists($className))
        return $this->privateData[$name] ? $this->privateData[$name] : ($local ? null : $this->privateData[$name] = new $className($this));
    }
    $properties = array_keys(get_class_vars($class));
    $plainClass = ucwords($name);
    $objectClass = $class::prefix . ucwords($name);

    $mf5ObjectClass = 'MF5' . ucwords($name);
    if (in_array($property, $properties) && class_exists($plainClass)) {
      return $this->privateData[$name] ? $this->privateData[$name] : ($local ? null : $this->privateData[$name] = $plainClass::withId($this->{$name . 'Id'}));
    } else if (in_array($property, $properties) && class_exists($objectClass)) {
      return $this->privateData[$name] ? $this->privateData[$name] : ($local ? null : $this->privateData[$name] = $objectClass::withId($this->{$name . 'Id'}));
    } else if (in_array($property, $properties) && class_exists($mf5ObjectClass)) {
      return $this->privateData[$name] ? $this->privateData[$name] : ($local ? null : $this->privateData[$name] = $mf5ObjectClass::withId($this->{$name . 'Id'}));
    }

    $infoClass = $class . ucwords($name);
    if (class_exists($infoClass) && $name != 'lists' && $name != 'info') {
      return $this->privateData[$name] ? $this->privateData[$name] : ($local ? null : $this->privateData[$name] = new $infoClass($this));
    }

    $superInfoClass = get_parent_class($this) ? get_parent_class($this) . ucwords($name) : null;
    if (class_exists($superInfoClass) && $name != 'lists' && $name != 'info') {
      return $this->privateData[$name] ? $this->privateData[$name] : ($local ? null : $this->privateData[$name] = new $superInfoClass($this));
    }

    return null;
  }

  function __set($name, $value)
  {
    $class = get_called_class();
    $objectId = "{$name}Id";
    if($this->{$objectId} && $value === null)
      $this->{$objectId} = null;
    if(is_object($value))
      $this->{$objectId} = $value->id;
    $this->privateData[$name] = $value;
  }

  /**
   * Get classes public properites and store globally for speed improvements
   */
  static function publicProperties()
  {
    global $classProperties;
    $class = get_called_class();
    if(!$classProperties[$class]){
      $ReflectionClass = new \ReflectionClass($class);
      $static = $ReflectionClass->getProperties(ReflectionProperty::IS_STATIC);
      $public = $ReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);
      $output = array();
      foreach ($public as $p) {
        $isStatic = false;
        foreach ($static as $s) {
          if($s->name == $p->name){
            $isStatic = true;
            break;
          }
        }
        if(!$isStatic)
          array_push($output,$p->name);
      }
      $classProperties[$class] = $output;
    }
    return $classProperties[$class];
  }

  function elasticsearch($options = array())
  {
    $es = array();
    if($this->id){
      $es['id'] = $this->id;
    }
    return $es;
  }

  /**
   * Serilize for API output
   * @param type $options
   * @return type
   */
  function assoc($options = array())
  {
    $class = get_called_class();
    $options = $class::options($options);

    $properties = $class::publicProperties();
    foreach ($properties as $property) {
      $value = $this->{$property};
      if ($value !== null) {
        if (is_object($value)) {
          if (method_exists($value, 'assoc')) {
            $result = $value->assoc($options);
            if ($result)
              $assoc[$property] = $result;
          } else
            $assoc[$property] = $value;
        } else if (is_array($value)) {
          $assoc[$property] = assoc($value);
        } else {
          if (preg_match('/^num/', $property) || $property == 'count')
            $value = intval($value);
          else if(preg_match('/^avg/', $property))
            $value = doubleval($value);
          $assoc[$property] = $value;
        }
      }
    }
    $assoc['_class'] = $class;
    return $assoc;
  }

  function elastic($options = array())
  {
    return $this->assoc($options);
  }

  function mongo($options = array())
  {
    $mongo['id'] = $this->id;
    return $mongo;
  }

  /**
   * Build a set of options for output of an object
   * @param type $custom
   * @return type
   */
  static function options($custom = array())
  {
    if (!is_array($custom))
      return array();
    return $custom;
  }

  /**
   * Map Router
   */
  static function map(&$api, $route)
  {
    $class = get_called_class();
    $class::mapEndpoints($api, $route);
    $class::mapGet($api, $route);
    $class::mapFind($api, $route);
    $class::mapPut($api, $route);
    $class::mapPost($api, $route);
    $class::mapDelete($api, $route);
    $class::mapLists($api, $route);
    $class::mapInfo($api, $route);
    $class::mapActions($api, $route);
  }

  static function mapEndpoints(&$api, $route)
  {
    $class = get_called_class();
    $endpoints = $class::$endpoints;
    foreach ($endpoints as $key => $endpoint) {
      if(is_numeric($key)){
        $api->addGet("{$route}/{$endpoint}", $class, $endpoint);
      }else if($endpoint == 'GET'){
        $api->addGet("{$route}/{$key}", $class, $key);
      }else if($endpoint == 'POST'){
        $api->addPost("{$route}/{$key}", $class, $key);
      }else{
        $endpoint::map($api, "$route/$key");
      }
    }
  }

  static function mapGet(&$api, $route)
  {
    $class = get_called_class();
    // GET object
    $api->router->get("$route/:id", function($id) use ($api, $class) {
      $object = $class::withId($id);
      if ($object) {
        $class = get_class($object);
        $options = $class::options(array_merge(
            array('details' => true), $api->router->request->get()
        ));
        $api->success(array('data' => $object->assoc($options)));
      } else {
        $api->error(404, 'Missing', 'Missing object');
      }
    });
  }

  static function mapFind(&$api, $route)
  {
    $class = get_called_class();
    // GET objects "search"
    if (method_exists($class, 'find')) {
      $api->router->get("$route", function() use ($api, $class) {
        $get = $api->router->request->get();
        if ($get['limit'])
          settype($get['limit'], 'int');
        if ($get['offset'])
          settype($get['offset'], 'int');
        $options = $class::options($get);
        $result = runClassMethod($class, 'find', $get);
        $numResults = sql()->foundRows;
        if ($numResults !== null) {
          $pagination['offset'] = $get['offset'] ? intval($get['offset']) : 0;
          $pagination['limit'] = intval($get['limit']);
          $pagination['numResults'] = $numResults;
          if ($get['total'] && $result['total'] && $result['data']) {
            $pagination['total'] = $result['total'];
            $result = $result['data'];
          }
          $response['stats'] = $pagination;
        }
        $response['data'] = assoc($result, $options);
        $api->success($response);
      });
    }
  }

  static function mapPut(&$api, $route)
  {
    $class = get_called_class();
    // PUT object "save"
    $api->router->put("$route/:id", function($id) use ($api, $class) {
      $body = $api->router->request->getBody();
      $input = json_decode($body, true);
      if ($input) {
        $object = $class::withId($id);
        if ($object) {
          $class = get_class($object);
          $object->save($input);
          $options = $class::options(array_merge(
              array('details' => true), $api->router->request->get()
          ));
          $api->success(array('data' => $object->assoc($options)));
        } else {
          $api->error(404, 'Missing', 'Missing object');
        }
      } else {
        $api->error(100, 'Missing body data', 'Missing data');
      }
    });
  }

  static function mapPost(&$api, $route)
  {
    $class = get_called_class();
    // POST object "create"
    $api->router->map("$route", function() use ($api, $class) {
      $body = json_decode($api->router->request->getBody(),true);
      $input = $body ? $body : $api->router->request->post();
      $params = $input;
      $params['input'] = $input;
      $object = $class::run('create2', $params);
      $options = $class::options(array_merge(
          array('details' => true), $api->router->request->get()
      ));
      if ($object)
        $api->success(array('data' => $object->assoc($options)));
      else
        $api->error(1100, 'CreateFailed', 'Failed to create objects');
    })->via('POST','PUT');
  }

  static function mapDelete(&$api, $route)
  {
    $class = get_called_class();
    // DELETE object
    $api->router->delete("$route/:id", function($id) use ($api, $class) {
      $object = $class::withId($id);
      if ($object) {
        // delete
        $object->delete();
        $api->success(array('data' => 'deleted object'));
      } else {
        $api->error(404, 'Missing', 'Missing object');
      }
    });
  }

  static function mapLists(&$api, $route)
  {
    $class = get_called_class();
    $lists = $class::$lists;
    foreach ($lists as $key => $list) {
      $list::map($api, "$route/:id/$key", $class, $key);
    }
  }

  static function mapInfo(&$api, $route)
  {
    $class = get_called_class();
    $infos = $class::$info;

    foreach ($infos as $key => $infoClass) {
      if(!class_exists($infoClass))
        return;
      $k = dasherize($key);
      $api->router->get("$route/:id/$k", function($id) use ($api, $class, $key) {
        $object = $class::withId($id);
        if ($object->{$key}) {
          $options = $class::options(array_merge(
            array('details' => true), $api->router->request->get()
          ));
          $api->success(array('data' => $object->{$key}->assoc($options)));
        } else {
          $api->error(404, 'Missing', 'Missing object');
        }
      });

      $api->router->put("$route/:id/$k", function($id) use ($api, $class, $key) {
        $body = $api->router->request->getBody();
        $input = json_decode($body, true);
        if ($input) {
          $object = $class::withId($id);
          if ($object->{$key}) {
            $class = get_class($object->{$key});
            $object->{$key}->save($input);
            $options = $class::options(array_merge(
                array('details' => true), $api->router->request->get()
            ));
            $api->success(array('data' => $object->{$key}->assoc($options)));
          } else {
            $api->error(404, 'Missing', 'Missing object');
          }
        } else {
          $api->error(100, 'Missing body data', 'Missing data');
        }
      });

      $actions = $infoClass::$actions;
      if(count($actions)){
        foreach ($actions as $action) {
          // REQUEST actions
          if (method_exists($infoClass, $action)) {
            $a = dasherize($action);
            $api->router->map("$route/:id/$k/$a", function($id) use ($api, $key, $class, $action) {
              $object = $class::withId($id);
              if ($object) {
                $params = array_merge($api->router->request->post(), $api->router->request->get());
                $args = collectArguments($object->{$key}, $action, $params);
                $result = call_user_func_array(array($object->{$key}, $action), array_values($args));
                $api->success(array('data' => assoc($result)));
              } else {
                $api->error(404, 'Missing', 'Missing object');
              }
            })->via('GET', 'POST');
          }
        }
      }else{
        $reflector = new ReflectionClass($infoClass);
        // to get the Method DocBlock
        $methods = $reflector->getMethods();
        foreach($methods as $method)
        {
          $matches = array();
          $comment = $method->getDocComment();
          if(preg_match('/@endpoint (GET|POST|REQUEST|DELETE|PUT)/', $comment, $matches)){
            $action = $method->getName();
            if($matches[1]){
              $a = dasherize($action);
              $api->router->map("$route/:id/$k/$a", function($id) use ($api, $key, $class, $action) {
                $object = $class::withId($id);
                if ($object) {
                  $params = array_merge($api->router->request->post(), $api->router->request->get());
                  $args = collectArguments($object->{$key}, $action, $params);
                  $result = call_user_func_array(array($object->{$key}, $action), array_values($args));
                  $api->success(array('data' => assoc($result)));
                } else {
                  $api->error(404, 'Missing', 'Missing object');
                }
              })->via('GET', 'POST');
            }
          }
        }
      }
    }
  }

  static function mapActions(&$api, $route)
  {
    $class = get_called_class();
    $actions = $class::$actions;
    if(count($actions)){
      foreach ($actions as $action) {
        // REQUEST actions
        $a = dasherize($action);
        if (method_exists($class, $action)) {
          $api->router->map("$route/:id/($a|$action)", function($id) use ($api, $class, $action) {
            $object = $class::withId($id);
            if ($object) {
              $params = array_merge($api->router->request->post(), $api->router->request->get());
              $args = collectArguments($class, $action, $params);
              $result = call_user_func_array(array($object, $action), array_values($args));
              $options = array();
              if (is_object($result)) {
                  $class = get_class($object);
                  $options = $class::options($params);
              }
              $api->success(array('data' => assoc($result,$options)));
            } else {
              $api->error(404, 'Missing', 'Missing object');
            }
          })->via('GET', 'POST');
        }
      }
    }else{
      $reflector = new ReflectionClass($class);

      // to get the Method DocBlock
      $methods = $reflector->getMethods();
      foreach($methods as $method)
      {
        $matches = array();
        $comment = $method->getDocComment();
        if(preg_match('/@endpoint (GET|POST|REQUEST|DELETE|PUT)/', $comment, $matches)){
          $action = $method->getName();
          if($matches[1]){
            $a = dasherize($action);
            $api->router->map("$route/:id/($a|$action)", function($id) use ($api, $class, $action) {
              $object = $class::withId($id);
              if ($object) {
                $params = array_merge($api->router->request->post(), $api->router->request->get());
                $args = collectArguments($class, $action, $params);
                $result = call_user_func_array(array($object, $action), array_values($args));
                $options = array();
                if (is_object($result)) {
                    $class = get_class($object);
                    $options = $class::options($params);
                }
                $api->success(array('data' => assoc($result,$options)));
              } else {
                $api->error(404, 'Missing', 'Missing object');
              }
            })->via('GET', 'POST');
          }
        }
      }
    }
  }

}

class ObjectInfo extends Object implements Endpoint
{

  /**
   * Initialize and object
   * @param type $dictionary
   * @return \class
   */
  static function init($dictionary = null, $parentObject = null)
  {
    $class = get_called_class();
    $object = new $class($parentObject);
    $object->load($dictionary);
    return $object;
  }

}

class ObjectList extends stdClass implements Endpoint
{

  const skip = 20, prefix = 'MF5';

  static $endpoints = array(), $actions = array();

  function __get($name)
  {
    if ($name == 'assoc' || $name == 'elastic' || $name == 'elasticsearch' || $name == 'mongo' || in_array($name, $class::$actions))
      return $this->$name();
    return parent::__get($name);
  }

  function assoc($options = array())
  {
    $class = get_called_class();
    $offset = $options['offset'] ? $options['offset'] : 0;
    $limit = $options['limit'] ? $options['limit'] : $class::skip;
    return assoc($this->find($offset, $limit), $options);
  }

  public static function options($custom = array())
  {
    if (!is_array($custom))
      return array();
    return $custom;
  }

  /**
   * Map Router
   */
  static function map(&$api, $route)
  {
    $class = get_called_class();

    // GET objects "search"
    if (method_exists($class, 'find')) {
      $api->router->get("$route", function($id) use ($api, $class) {
        $options = array_merge(
                $class::options(), $api->router->request->get()
        );
        $objects = $class::find($api->router->request->get());
        $api->success(array('data' => assoc($objects, $options)));
      });
    }

    // POST object "create"
    $api->router->post("$route", function($id) use ($api, $class) {
      $object = null;
      $api->success(array('data' => $object->assoc));
    });
  }

}
