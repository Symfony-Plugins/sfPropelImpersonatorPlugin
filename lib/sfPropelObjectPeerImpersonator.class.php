<?php

/**
 * sfPropelObjectPeerImpersonator
 *
 * A class that imitates in some way Peer static classes, for doing custom queries.
 * This class is not static, but the ->doSelect should return an array of
 * sfPropelObjectImpersonator which will implements same methods as usual propel's
 * BaseObject subclasses (in the limit of what was actually implemented for our
 * admin generation/object helper needs, or any other class provided at construction
 * time.
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
 *         $previousobject->customCamelizedFieldName (property, not method)
 *   - custom_related_by_reverse:
 *         If you want to get a relation link back using custom_related_by, specify the
 *         underscored version of the field making link. sfPopi will call ->setCamelized(...)
 *         on the customized object.
 *
 * To enable fast debugging of whether or not a record fetching problem may have been caused
 * by the plugin, every object populated by sfPopi is flagged with the property
 * ->populatedByImpersonator set to true. For more extensive debugging, try changing the
 * DEBUG and DEBUG_POPULATE constants to true, and use firebug to see relation tree built
 * using propel introspection methods.
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
   * Set to true to enable basic debugging output
   */
  const DEBUG = false;

  /**
   * Set to true to enable advanced object population debugging output
   */
  const DEBUG_POPULATE =  false;

  /**
   * Relation type constants
   */
  const RELATION_NORMAL  = 1;
  const RELATION_REVERSE = 2;
  const RELATION_I18N    = 3;
  const RELATION_SELF    = 4;

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

  protected
    $objectsParameters = array(),
    $objectsStartColumns = array(),
    $currentStartColumnForPropelObjects = 1,
    $currentStartColumnForImpersonatedObjects = 0,
    $classToIndex,
    $relations,
    $currentIndex;

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
    foreach($this->objects as $object)
    {
      if (self::isPropelObject($object))
      {
        call_user_func(array(get_class($object).'Peer', 'addSelectColumns'), $c);
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
      $_class_from = get_class($oFrom);

      if (null !== ($peer = $oFrom->getPeer()))
      {
        foreach ($oFrom->getPeer()->getMapBuilder()->getDatabaseMap()->getTable(constant($_class_from.'Peer::TABLE_NAME'))->getColumns() as $c)
        {
          if ($c->isForeignKey())
          {
            $_class_to = sfInflector::camelize($c->getRelatedTableName());

            if ($_class_to != $_class_from)
            {
              // Normal relations (class a => class b)
              if (null !== ($iTo = $this->getIndexByClass($_class_to)))
              {
                if ($iTo > $iFrom)
                {
                  if (!isset($this->relations[$iTo]))
                  {
                    $this->relations[$iTo] = array();
                  }

                  $this->relations[$iTo][$iFrom] = array(
                      'type'         => self::RELATION_REVERSE,
                      'classTo'      => $_class_to,
                      'classFrom'    => $_class_from,
                      'local'        => $c->getPhpName(),
                      'distant'      => $c->getRelatedTableName(),
                      );
                }
                else
                {
                  if (!isset($this->relations[$iFrom]))
                  {
                    $this->relations[$iFrom] = array();
                  }

                  if (strpos($_class_from, 'I18n') !== false)
                  {
                    $this->relations[$iFrom][$iTo] = array(
                        'type'         => self::RELATION_I18N,
                        'classTo'      => $_class_to,
                        'classFrom'    => $_class_from,
                        'local'        => $c->getPhpName(),
                        'distant'      => $c->getRelatedTableName(),
                        );
                  }
                  else
                  {
                    $this->relations[$iFrom][$iTo] = array(
                        'type'         => self::RELATION_NORMAL,
                        'classTo'      => $_class_to, 
                        'classFrom'    => $_class_from,
                        'local'        => $c->getPhpName(),
                        'distant'      => $c->getRelatedTableName(),
                        );
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

    if (self::DEBUG)
    {
      echo '<b>QUERY</b> (may not be applicable):'.sfPropelCriteriaImpersonator::getSql($c);
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
  public function doCount(Criteria $criteria, $distinct = false, $con=null)
  {
    $criteria = clone $criteria;
    $asColumns = $criteria->getAsColumns();
    $criteria->clearSelectColumns()->clearOrderByColumns();

    // hack against criteria private policy
    foreach ($asColumns as $key => $value)
    {
      $criteria->addAsColumn($key, $value);
    }

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

    $rs = call_user_func(array($peerClass, 'doSelectRS'), $criteria, $con);

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
   */
  public function doQuery()
  {
    if ($this->query)
    {
      if (!$this->connection)
      {
        $this->setConnection();
      }

      $statement = $this->connection->PrepareStatement($this->query);
      $this->currentIndex = 1;

      foreach(func_get_args() as $argument)
      {
        $this->setPreparedParameter($statement, $argument);
      }

      $resultset = $statement->executeQuery(ResultSet::FETCHMODE_NUM);
      return $this->populateObjects($resultset);
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

  /**
   * Object population
   */
  public function populateObjects(ResultSet $resultset)
  {
    $this->initializeRelations();

    // debug block
    if (self::DEBUG && self::DEBUG_POPULATE)
    {
      $debugRelations = array();

      foreach ($this->relations as $from => $fromData)
      {
        $fromClass = get_class($this->objects[$from]);

        $debugRelations[$fromClass] = array();
        foreach ($fromData as $to => $relationsData)
        {
          $toClass = get_class($this->objects[$to]);

          $debugRelations[$fromClass][$toClass] = $relationsData;
        }
      }

      echo '<script>console.dir('.json_encode(array('Propel Impersonator Debug'=>$debugRelations)).');</script>';
      echo '<pre>';
    }

    $result = array();
    $allObjects = array();

    // for each record....
    while ($resultset->next())
    {
      if (self::DEBUG && self::DEBUG_POPULATE)
      {
        echo '-------------------- fetch a record --------------------'."\n";
      }

      $rowObjects = array();
      $currentImpersonatedObjectsStartColumn = 0;

      // for each subcomponent we have to hydrate in each record....
      foreach ($this->objects as $index => $object)
      {
        if (self::DEBUG && self::DEBUG_POPULATE)
        {
          echo '<b>'.get_class($object).'</b>'."\n";
        }

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
              $linkedRelationsCounter = 0;

              foreach ($this->getRelationsFor($index) as $relation)
              {
                $currentObject = $rowObjects[$index];

                switch ($relation['type'])
                {
                  /**
                   * RELATION_REVERSE
                   */
                  case self::RELATION_REVERSE:
                    if (self::DEBUG && self::DEBUG_POPULATE)
                    {
                      echo '  <u>REVERSE</u>: '.$relation['classFrom'].' <-- '.$relation['classTo'];
                    }

                    $foreignClass = $relation['classFrom'];
                    $foreignObject = $rowObjects[$this->getIndexByClass($foreignClass)];
                    $relatedBy = (null === ($_relatedBy=$this->getParameter($index, 'related_by')))?'':'RelatedBy'.sfInflector::camelize($_relatedBy);

                    if ($isNewObject)
                    {
                      if (self::DEBUG && self::DEBUG_POPULATE)
                      {
                        echo ' (new)';
                      }


                      $currentObject->{'init'.$foreignClass.'s'.$relatedBy}();
                    }

                    $currentObject->{'add'.$foreignClass.$relatedBy}($foreignObject);
                    $foreignObject->{'set'.$relation['classTo'].$relatedBy}($currentObject);

                    $linkedRelationsCounter++;
                    break;

                  /**
                   * RELATION_I18N
                   */
                  case self::RELATION_I18N:
                    if (self::DEBUG && self::DEBUG_POPULATE)
                    {
                      echo '  <u>I18N</u>: '.$relation['classTo'].' <-- '.$relation['classFrom'];
                    }

                    if (null !== ($objectIndex = $this->getIndexByClass($relation['classTo'])))
                    {
                      if (self::DEBUG && self::DEBUG_POPULATE)
                      {
                        echo ' (exists)';
                      }

                      $object = $rowObjects[$objectIndex];
                      $i18nObject = $rowObjects[$index];

                      $i18nObject->{'set'.$relation['classTo']}($object);
                      $object->{'set'.$relation['classTo'].'I18nForCulture'}($i18nObject, $this->getCulture());

                      $linkedRelationsCounter++;
                    }
                    break;

                  /**
                   * RELATION_NORMAL
                   */
                  case self::RELATION_NORMAL:
                    if (self::DEBUG && self::DEBUG_POPULATE)
                    {
                      echo '  <u>NORMAL</u>: '.$relation['classFrom'].' --> '.$relation['classTo'];
                    }

                    if (null !== ($classToIndex = $this->getIndexByClass($relation['classTo'])))
                    {
                      if (self::DEBUG && self::DEBUG_POPULATE)
                      {
                        echo ' (exists)';
                      }
                      $foreignObject = $rowObjects[$classToIndex];

                      $relatedBy = (null === ($_relatedBy=$this->getParameter($classToIndex, 'related_by')))?'':'RelatedBy'.sfInflector::camelize($_relatedBy);
                      // @todo: bug correction, this does not work if related_by is used this way, it tries to call setFieldRelatedByFieldId
                      // instead of setClassNameRelatedByFieldId, and sometimes (depending on order) it uses the wrong Field, as second one
                      // override first one in relations

                      // local *---- foreign (local object has one foreign object)
                      $rowObjects[$index]->{'set'.$relation['classTo'].$relatedBy}($foreignObject);

                      // foreign ----* local (foreign object has many local objects)
                      // we have to check if there are already other objects, or if this is the first.
                      // @todo: find a way not to call initXxxXxxs() on every passes, but only on first addition for each object.

                      $foreignObject->{'init'.$relation['classFrom'].'s'.$relatedBy}($rowObjects[$index]);
                      $foreignObject->{'add'.$relation['classFrom'].$relatedBy}($rowObjects[$index]);

                      $linkedRelationsCounter++;
                    }
                    break;

                  case self::RELATION_SELF:
                    throw new sfException('Self relations not yet implemented');
                    break;
                }


                if (self::DEBUG && self::DEBUG_POPULATE)
                {
                  echo "\n";
                }

              }

              if (!$linkedRelationsCounter)
              {
                // this should not happen. An object which is not our main object has not been linked to any other
                // fetched object.
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

            $rowObjects[$index-1]->{'custom'.sfInflector::camelize($this->getParameter($index, 'custom_related_by'))} = $rowObjects[$index];

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

    if (self::DEBUG && self::DEBUG_POPULATE)
    {
      echo '</pre>';
    }

    return $result;
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
      throw new Exception('Tried to retrievea parameter for an unknown object name or index.');
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
      $this->addObject(new sfPropelObjectImpersonator($fields));
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
}
