<?php

/**
 * sfPropelObjectPeerImpersonator
 *
 * A class that imitates in some way Peer static classes, for doing custom queries.
 * This class is not static, but the ->doSelect should return an array of
 * sfPropelObject which implements same methods as usual propel's BaseObject subclasses
 * (in the limit of what was actually implemented for our  admin generation/object helper
 * needs, or any other class provided at construction time.
 *
 * Constructor takes mixed arguments, either strings or arrays, and arrays can be
 * containing more arrays in the same form, only matters is the order. Thoose arguments
 * describe what will be populated after a ->doSelect() or ->doQuery() call.
 *
 * Strings are a shortcut for array('object', ...), and can take optional parameters,
 * in the form 'string param1=value1 param2=value2', within the following:
 *   - related_by:
 *         use custom method for populating relation, usefull when two classes are
 *         linked by more than one relation.
 *   - custom_related_by:
 *         when using custom sql that returns the same fields as a propel object, you
 *         can use this to populate a fake relation. for example, if a table materialize
 *         page views, you could return the same structure with sums, avgs and cie
 *         replacing the value field. Custom related class will be available through 
 *         $previousobject->customCamelizedFieldName property, or if this field is
 *         specified as custom_related_by=->setterMethod it will fetch relation using
 *         the given method in previous object
 *   - custom_related_by_reverse:
 *         If you want to get a relation link back using custom_related_by, specify the
 *         underscored version of the field making link. sfPopi will call ->setCamelized(...)
 *         on the customized object.
 *   - alias:
 *         Provides a way to set an alias name for the table. This allows you to select a table
 *         more than once using propel.
 *
 *
 * KNOWN PROBLEMS:
 *  - don't use propel classes starting with lowercase or not being camelcased. Definately.
 *    I don't think we can find a workaround for this, propel does not give enough informations
 *    in introspection classes
 *  - everything flagged as @todo in this file.
 *
 * @package sfPropelImpersonatorPlugin
 * @subpackage lib
 * @author Romain Dorgueil <romain.dorgueil@sensio.com>
 * @version SVN: $Id$
 */

class sfPropelObjectPeerImpersonator
{
  /**
   * Custom SQL query, set by ->setQuery()
   */
  protected $query;

  /**
   * Propel connection
   */
  protected $connection;

  /**
   * Prototype instances of objects each row will populate
   */
  protected $objects = array();

  /**
   * Parameter containers for objects
   */
  protected $objectsParameters = array();

  protected
    $objectsStartColumns = array(),
    $currentStartColumnForPropelObjects = 1,
    $currentStartColumnForImpersonatedObjects = 0,
    $classToIndex,
    $currentIndex;

  /**
   * Relations directory extracted from Propel database map
   */
  protected $relations;

  /**
   * User culture, sued for I18n joins
   */
  protected $culture = null;

  /**
   * Flag forbiding to populate more or less fields than we selected. Prevent populating objects with
   * values not meant for them, but reduce flexibility.
   *
   * Use ->(enable|disable)SecurityFieldCountFlag() to change.
   */
  protected $securityFieldCountFlag = true;

  /**
   * Constructor
   *
   * Prepare an object to dynamicaly imitate an imaginary peer class.
   * Takes a variable number of parameters, each one can be an array(type, fieldname)
   * or a propel object name.
   *
   * @param mixed
   */
  public function __construct()
  {
    if (count($args = func_get_args()))
    {
      $customFields = array();
      $this->parseConstructorParameters($args, $customFields, true);
    }
    else
    {
      throw new Exception('No parameters given to constructor, aborting.');
    }
  }

  /**
   * Prepare custom SQL query
   */
  public function setQuery($query)
  {
    $this->query = preg_replace('/\s++/', ' ', $query);
  }

  /**
   * Select database connection
   */
  public function setConnection($connection=null)
  {
    $this->connection = Propel::getConnection($connection);
  }

  /**
   * Shortcut to call addSelectColumns on every objects we'll want to populate
   *
   * @param Criteria $c
   */
  public function addSelectColumns(Criteria $c)
  {
    foreach($this->objects as $index => $object)
    {
      $peerClassName = get_class($object).'Peer';

      if (self::isPropelObject($object))
      {
        if (null!==($alias=$this->getParameter($index, 'alias')))
        {
          $objectFields = call_user_func(array($peerClassName, 'getFieldNames'), BasePeer::TYPE_COLNAME);

          $c->addAlias($alias, constant($peerClassName.'::TABLE_NAME'));

          foreach ($objectFields as $objectField)
          {
            $c->addSelectColumn(call_user_func(array($peerClassName, 'alias'), $alias, $objectField));
          }
        }
        else
        {
          call_user_func(array($peerClassName, 'addSelectColumns'), $c);
        }
      }
      else
      {
        $object->addSelectColumns($c);
      }
    }
  }

  /**
   * Retrieve relations between objects for future population
   */
  protected function initializeRelations()
  {
    $this->relations = array();

    foreach ($this->objects as $iFrom => $oFrom)
    {
      if (null !== ($peer = $oFrom->getPeer()))
      {
        $_class_from = get_class($oFrom);
        $databaseMap = $oFrom->getPeer()->getMapBuilder()->getDatabaseMap();

        foreach ($databaseMap->getTable(constant($_class_from.'Peer::TABLE_NAME'))->getColumns() as $c)
        {
          if ($c->isForeignKey())
          {
            $relatedTableName = $c->getRelatedTableName();
            if (!in_array($relatedTableName, array_keys($databaseMap->getTables())))
            {
              // this relation is not needed
              continue;
            }
            $_class_to = $databaseMap->getTable($relatedTableName)->getPhpName();

            if ($_class_to != $_class_from)
            {
              if (null !== ($iTo = $this->getIndexByClass($_class_to)))
              {
                if ($iTo > $iFrom)
                {
                  if (!isset($this->relations[$iTo]))
                  {
                    $this->relations[$iTo] = array();
                  }

                  $this->relations[$iTo][$iFrom] = new sfPropelImpersonatorManyToOneRelation($iTo, $_class_from, $iFrom, $_class_to, $iTo, $c->getPhpName(), $c->getRelatedTableName(), array('from'=>$this->getParameters($iTo), 'to'=>$this->getParameters($iFrom)));
                }
                else
                {
                  if (!isset($this->relations[$iFrom]))
                  {
                    $this->relations[$iFrom] = array();
                  }

                  if (substr($_class_from, -4) == 'I18n')
                  {
                    $this->relations[$iFrom][$iTo] = new sfPropelImpersonatorI18nRelation($iFrom, $_class_from, $iFrom, $_class_to, $iTo, $c->getPhpName(), $c->getRelatedTableName(), array('from'=>$this->getParameters($iFrom), 'to'=>$this->getParameters($iTo)), $this->getCulture());
                  }
                  else
                  {
                    $this->relations[$iFrom][$iTo] = new sfPropelImpersonatorOneToManyRelation($iFrom, $_class_from, $iFrom, $_class_to, $iTo, $c->getPhpName(), $c->getRelatedTableName(), array('from'=>$this->getParameters($iFrom), 'to'=>$this->getParameters($iTo)));
                  }
                }
              }
            }
            else
            {
              // @todo Self relations, not handled for now
              // Type is self::RELATION_SELF
            }
          }
        }
      }
    }
  }

  /**
   * Object population
   */
  public function populateObjects(ResultSet $resultset)
  {
    $this->initializeRelations();
    $result = array();
    $allObjects = array();

    // for each record....
    while ($resultset->next())
    {
      $rowObjects = array();
      $currentImpersonatedObjectsStartColumn = 0;

      // for each subcomponent we have to hydrate in each record....
      foreach ($this->objects as $index => $object)
      {
        $rowObjects[$index] = clone $object;

        if (self::isPropelObject($object))
        {
          $rowObjects[$index]->hydrate($resultset, $this->objectsStartColumns[$index]);
        }
        else
        {
          $rowObjects[$index]->hydrate($resultset, $this->currentStartColumnForPropelObjects + $currentImpersonatedObjectsStartColumn);
          $currentImpersonatedObjectsStartColumn += $this->objectsStartColumns[$index];
        }

        /*
          @todo think about what we'll do with this.

          if the object was only made of null values, we consider it's inconsistent and forget it:

          if (!$this->testConsistence($rowObjects[$index]->getPrimaryKey()))
          {
            unset($rowObjects[$index]);
            continue;
          }

          for now, let's say we keep it

          reason is: if we dont populate an empty propel object, propel will think we don't know it does not exists, and
          will fetch it again from database.

          maybe this could be implemented with a minimal NULL object (ok i know it looks like Doctrine_Null)
        */

        // initialize our object directory
        if (!isset($allObjects[$index]))
        {
          $allObjects[$index] = array();
        }

        // check if object is not already referenced in allObjects directory
        $isNewObject = true;

        if (self::isPropelObject($this->objects[$index]))
        {
          foreach ($allObjects[$index] as $otherObject)
          {
            if ($otherObject->getPrimaryKey() === $rowObjects[$index]->getPrimaryKey())
            {
              $isNewObject = false;
              $rowObjects[$index] = $otherObject;
              break;
            }
          }
        }

        // reference it
        if ($isNewObject)
        {
          $allObjects[$index][] = $rowObjects[$index];
        }

        // If we're not in our "main" object context but in a sub-object, we're going to
        // fetch the available relations.
        if ($index/*&&$isNewObject*/)
        {
          if (null===$this->getParameter($index, 'custom_related_by', null))
          {
            // normal relation fetching (ie Propel BaseObjcet children)
            if (self::isPropelObject($this->objects[$index]))
            {
              $_linkedRelationsCounter = 0;

              foreach ($this->getRelationsFor($index) as $relation)
              {
                $currentObject = $rowObjects[$index];

                if ($relation->link($rowObjects, $index))
                {
                  $_linkedRelationsCounter++;
                }
              }

              if (!$_linkedRelationsCounter)
              {
                // this should not happen if you did not make a mistake in your request. An object which is
                // not our main object has not been linked to any other fetched object.
                throw new sfException('Orphan object fetched of type '.get_class($rowObjects[$index]));
              }
            }
            else
            {
              assert($index-1>=0);

              $rowObjects[$index-1]->extra = $rowObjects[$index];
            }
          }
          else
          {
            // user specified a relation, ignore propel introspection relations
            assert($index-1>=0);

            $method = $this->getParameter($index, 'custom_related_by');

            if (substr($method, 0, 2)=='->')
            {
              $rowObjects[$index-1]->{substr($method, 2)}($rowObjects[$index]);
            }
            else
            {
              $rowObjects[$index-1]->{'custom'.$method} = $rowObjects[$index];
            }

            if (strlen($_field=sfInflector::camelize($this->getParameter($index, 'custom_related_by_reverse'))))
            {
              $rowObjects[$index]->{'set'.$field}($rowObjects[$index-1]);
            }
          }
        }

        // link our main object
        if (!$index && $isNewObject)
        {
          $result[] =& $rowObjects[0];
        }
      }
    }

    return $result;
  }


  /**
   * Recursively parse parameters and prepare values in statement.
   */
  private function setPreparedParameter(&$statement, $arg)
  {
    if (!is_array($arg))
    {
      throw new sfException('Wrong argument passed, must be two items array, or array of two items array');
    }
    else
    {
      if (is_array($arg[0]))
      {
        foreach ($arg as $_arg)
        {
          $this->setPreparedParameter($statement, $_arg);
        }
      }
      elseif (count($arg)==2)
      {
        list($type, $value) = $arg;
        $setter = 'set'.ucfirst($type);

        if (is_array($value))
        {
          foreach($value as $_value)
          {
            $statement->$setter($this->currentIndex++, $_value);
          }
        }
        else
        {
          $statement->$setter($this->currentIndex++, $value);
        }
      }
      else
      {
        throw new sfException('Wrong argument passed, must be two items array, or array of two items array');
      }
    }
  }

  /**
   * Propel impersonating query
   */
  public function doSelect(Criteria $c, $con=null)
  {
    if ($con||!$this->connection)
    {
      $this->setConnection($con);
    }

    if ($this->securityFieldCountFlag && ($_acceptable=($this->currentStartColumnForPropelObjects + $this->currentStartColumnForImpersonatedObjects - 1)) != ($_got = count($c->getSelectColumns())+count($c->getAsColumns())))
    {
      throw new sfException('Criteria selects '.$_got.' fields while the Holy sfPropelImpersonator waits for '.$_acceptable.' fields to populate objects. Exactly. Not more, not less. So '.$_acceptable.' will be the field count your Criteria will ask for. You won\'t give it '.($_acceptable-1).', neither '.($_acceptable+1).', but '.$_acceptable.'.');
    }

    return $this->populateObjects(BasePeer::doSelect($c, $con));
  }

  /**
   * Propel impersonating doCount ^^
   */
  public function doCount(Criteria $criteria, $distinct = false, $con=null, $usePeerClassSelectResultSetMethod=true)
  {
    $criteria = sfPropelCriteriaImpersonator::clearSelectColumns($criteria);

    $peerClass = get_class($this->objects[0]).'Peer';

    if ($distinct || in_array(Criteria::DISTINCT, $criteria->getSelectModifiers()))
    {
      $criteria->addSelectColumn(constant($peerClass.'::COUNT_DISTINCT'));
    }
    else
    {
      $criteria->addSelectColumn(constant($peerClass.'::COUNT'));
    }

    foreach($criteria->getGroupByColumns() as $column)
    {
      $criteria->addSelectColumn($column);
    }

    if ($usePeerClassSelectResultSetMethod)
    {
      // kept for compatibility, but maybe useless. Problem with this is that doSelectRS can modify
      // criteria, especially because of propel behaviours. That should be ok, but doSelect do not use
      // it, so you can't use same criteria for both.
      //
      // for now, $usePeerClassSelectResultSetMethod is true by default
      $rs = call_user_func(array($peerClass, 'doSelectRS'), $criteria, $con);
    }
    else
    {
      $rs = BasePeer::doSelect($criteria, $con);
    }

    if ($rs->next())
    {
      return $rs->getInt(1);
    }
    else
    {
      return 0;
    }
  }

  /**
   * Custom query select
   *
   * @param mixed
   * @param mixed
   *   ...
   * @param mixed
   *
   * @return array
   */
  public function doQuery()
  {
    return $this->populateObjects($this->doRawQuery(func_get_args()));
  }

  /**
   * Custom query
   *
   * @param array $arguments
   *
   * @return ResultSet
   */
  public function doRawQuery(array $arguments=array())
  {
    if ($this->query)
    {
      if (!$this->connection)
      {
        $this->setConnection();
      }

      $statement = $this->connection->PrepareStatement($this->query);
      $this->currentIndex = 1;

      foreach($arguments as $argument)
      {
        $this->setPreparedParameter($statement, $argument);
      }

      return $statement->executeQuery(ResultSet::FETCHMODE_NUM);
    }
    else
    {
      throw new sfException('You must call ->setQuery() before being able to ->doQuery()');
    }
  }

  public function setCulture($culture)
  {
    $this->culture = mysql_real_escape_string($culture);
  }

  public function getCulture()
  {
    if (null === $this->culture)
    {
      $this->setCulture(sfContext::getInstance()->getUser()->getCulture());
    }

    return $this->culture;
  }
/*
  public function getJoinExtension($field, $field2, $linkOperator, $comparisonOperator)
  {
    return ' '.$linkOperator.' ('.$field.' '.$comparisonOperator.' '.$field2.') ';
  }
*/
  public function getJoinForCulture($class, $idField)
  {
    return constant($class.'::'.$idField).' AND '.constant($class.'::CULTURE').'=\''.$this->getCulture().'\'';
  }

  public function disableSecurityFieldCountFlag()
  {
    $this->securityFieldCountFlag = false;
  }

  public function enableSecurityFieldCountFlag()
  {
    $this->securityFieldCountFlag = true;
  }

  /**
   * Checks whether a primary key is consistent or not. Used to know if an object retrieved via a
   * left/right join (or equivalent) was null or not.
   *
   * @param mixed $key
   *
   * @return boolean
   */
  protected function testConsistence($key)
  {
    $isConsistent = true;

    if (is_array($key))
    {
      foreach ($key as $key_part)
      {
        $isConsistent = $isConsistent && $this->testConsistence($key_part);
      }
    }
    elseif ($key === null)
    {
      $isConsistent = false;
    }

    return $isConsistent;
  }

  protected function getRelationsFor($index)
  {
    return isset($this->relations[$index]) ? $this->relations[$index] : array();
  }

  /**
   * Merge a new parameters set with existing one for object indexed by $objectIndex
   *
   * @param integer $objectIndex
   * @param array   $parameters
   */
  protected function setParameters($objectIndex, array $parameters)
  {
    if (!isset($this->objectsParameters[$objectIndex]))
    {
      $this->objectsParameters[$objectIndex] = array();
    }

    $this->objectsParameters[$objectIndex] = array_merge($this->objectsParameters[$objectIndex], $parameters);
  }

  /**
   * Retrieve a parameter for an object
   *
   * @param mixed  $objectIndexOrName
   * @param string $parameterName
   * @param mixed  $defaultValue
   *
   * @return mixed
   */
  public function getParameter($objectIndexOrName, $parameterName, $defaultValue=null)
  {
    if (!is_numeric($objectIndexOrName))
    {
      $objectIndexOrName = $this->getIndexByClass($objectIndexOrName);
    }

    if (null===$objectIndexOrName || !isset($this->objects[$objectIndexOrName]))
    {
      throw new Exception('Tried to retrieve a parameter for an unknown object name or index.');
    }

    if (!isset($this->objectsParameters[$objectIndexOrName]) || !isset($this->objectsParameters[$objectIndexOrName][$parameterName]))
    {
      return $defaultValue;
    }
    else
    {
      return $this->objectsParameters[$objectIndexOrName][$parameterName];
    }
  }

  /**
   * Retrieve parameters for an object
   *
   * @param mixed  $objectIndexOrName
   *
   * @return mixed
   */
  public function getParameters($objectIndexOrName)
  {
    if (!is_numeric($objectIndexOrName))
    {
      $objectIndexOrName = $this->getIndexByClass($objectIndexOrName);
    }

    if (null===$objectIndexOrName || !isset($this->objects[$objectIndexOrName]))
    {
      throw new Exception('Tried to retrieve a parameter for an unknown object name or index.');
    }

    if (!isset($this->objectsParameters[$objectIndexOrName]))
    {
      return array();
    }
    else
    {
      return $this->objectsParameters[$objectIndexOrName];
    }
  }


  static public function isPropelObject($object)
  {
    return $object instanceof BaseObject;
  }

  /**
   * Adds an object instance to the current objects array
   */
  protected function addObject($instance, $parameters=null)
  {
    $index = count($this->objects);

    $instance->populatedByImpersonator = true;

    $this->objects[$index] = $instance;

    if (self::isPropelObject($instance))
    {
      $this->objectsStartColumns[$index] = $this->currentStartColumnForPropelObjects;
      $this->currentStartColumnForPropelObjects += count($instance->toArray());
    }
    else
    {
      $this->objectsStartColumns[$index] = $this->currentStartColumnForImpersonatedObjects;
      $this->currentStartColumnForImpersonatedObjects += count($instance->getColumns());
    }

    if (null!==$parameters)
    {
      $this->setParameters($index, $parameters);
    }
  }


  protected function addObjectByClassName($className)
  {
    // find whether or not we have parameters set for this object
    if (false!==strpos($className, ' '))
    {
      // separate classname from parameters
      $_parameters = explode(' ', $className);
      $className = array_shift($_parameters);

      // transform parameters into useable array
      $_parameters = array_map(create_function('$v', 'return explode(\'=\', $v);'), $_parameters);
      $parameters = array();

      foreach ($_parameters as $parameter)
      {
        if (count($parameter)==2)
        {
          $parameters[$parameter[0]] = $parameter[1];
        }
        else
        {
          throw new Exception('Invalid parameter given');
        }
      }
    }

    // add object
    if (class_exists($className, true))
    {
      if (isset($parameters))
      {
        $this->addObject(new $className(), $parameters);
      }
      else
      {
        $this->addObject(new $className());
      }
    }
    else
    {
      throw new Exception(__CLASS__.'::initialize(): Unknown object class given "'.$className.'"');
    }
  }


  protected function addCustomObjectIfApplicable(&$fields)
  {
    if (count($fields))
    {
      $this->addObject(new sfPropelObject($fields));
      $fields = array();
      return true;
    }

    return false;
  }

  /**
   * Return the index of given className in current objects array
   */
  protected function getIndexByClass($className)
  {
    if (!is_array($this->classToIndex))
    {
      $this->classToIndex = array();

      foreach ($this->objects as $index => $object)
      {
        $this->classToIndex[get_class($object)] = $index;
      }
    }

    if (isset($this->classToIndex[$className]))
    {
      return $this->classToIndex[$className];
    }
    else
    {
      return null;
    }
  }

  /**
   * Constructor parameters parser
   *
   * Takes a list of strings (shortcut for array('object', 'PropelObjectClassName')), array(type, name) and array(arrays).
   */
  protected function parseConstructorParameters($args, &$customFields, $finalize=false)
  {
    foreach ($args as $arg)
    {
      if (is_array($arg))
      {
        // ignore empty arrays

        if (isset($arg[0]))
        {
          if (is_array($arg[0]))
          {
            // Array of array, recursion

            $this->parseConstructorParameters($arg, $customFields);
          }
          elseif ('object'==$arg[0])
          {
            // Propel object, long version

            if (!isset($arg[1]))
            {
              throw new Exception('Invalid constructor item found: An object must take the object class name as parameter');
            }
            else
            {
              $this->addCustomObjectIfApplicable($customFields);
              $this->addObjectByClassName($arg[1]);
            }
          }
          else
          {
            // Pure custom field

            array_push($customFields, $arg);
          }
        }
      }
      else
      {
        // Propel object, short version. Will only be applicable on first level, deeper levels must use the
        // long version (ie array('object', 'PropelObjectClassName'))

        $this->addCustomObjectIfApplicable($customFields);
        $this->addObjectByClassName($arg);
      }
    }

    // If we're in top recursion level, add remaining custom objects if any
    if ($finalize)
    {
      $this->addCustomObjectIfApplicable($customFields);
    }
  }
}
