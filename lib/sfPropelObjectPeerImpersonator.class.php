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
 * @package veolys
 * @subpackage lib
 * @author Romain Dorgueil <romain.dorgueil@sensio.net
 * @version SVN: $Id$
 */
class sfPropelObjectPeerImpersonator
{
  const DEBUG = false;
  const DEBUG_POPULATE = false;

  /**
   * Relation type constants
   */
  const RELATION_NORMAL  = 1;
  const RELATION_REVERSE = 2;
  const RELATION_I18N    = 3;
  const RELATION_SELF    = 4;

  /**
   * Internal properties
   */
  protected
    $objectClass,
    $query,
    $connection,
    $objects,
    $classToIndex,
    $relations,
    $currentIndex,
    $culture = null;

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
    $args = func_get_args();
    $this->objects = array();

    if (count($args))
    {
      $custom_fields = array();

      foreach ($args as $arg)
      {
        if (is_array($arg))
        {
          array_push($custom_fields, $arg);
        }
        else
        {
          $this->addCustomObjectIfApplicable($custom_fields);
          $this->addObjectByClassName($arg);
        }
      }

      $this->addCustomObjectIfApplicable($custom_fields);
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
    $this->query = $query;
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
      $peer = get_class($object).'Peer';

      call_user_func(array($peer, 'addSelectColumns'), $c);
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
                        'classFrom'      => $_class_from,
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

    return $this->populateObjects(BasePeer::doSelect($c, $con));
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

      $startcol = 1;
      $rowObjects = array();

      // for each subcomponent we have to hydrate in each record....
      foreach ($this->objects as $index => $object)
      {
        if (self::DEBUG && self::DEBUG_POPULATE)
        {
          echo '<b>'.get_class($object).'</b>'."\n";
        }

        $rowObjects[$index] = clone $object;
        $startcol = $rowObjects[$index]->hydrate($resultset, $startcol);

        // if the object was only made of null values, we consider it's inconsistent and forget it.
/*        if (!$this->testConsistence($rowObjects[$index]->getPrimaryKey()))
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

        if (get_class($this->objects[$index])!='sfPropelObjectImpersonator')
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

        if ($isNewObject)
        {
          $allObjects[$index][] = $rowObjects[$index];
        }

        // If we're not in our "main" object context but in a sub-object, we're going to
        // fetch the available relations.
        if ($index/*&&$isNewObject*/)
        {
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

                if ($isNewObject)
                {
                  if (self::DEBUG && self::DEBUG_POPULATE)
                  {
                    echo ' (new)';
                  }

                  $currentObject->{'init'.$foreignClass.'s'}();
                }

                $currentObject->{'add'.$foreignClass}($foreignObject);
                $foreignObject->{'set'.$relation['classTo']}($currentObject);
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

                  // local *---- foreign (local object has one foreign object)
                  $rowObjects[$index]->{'set'.str_replace('Id','',$relation['local'])}($foreignObject);

                  // foreign ----* local (foreign object has many local objects)
                  // we have to check if there are already other objects, or if this is the first.
                  // @todo: find a way not to call initXxxXxxs() on every passes, but only on first addition for each object.

                  $foreignObject->{'init'.$relation['classFrom'].'s'}($rowObjects[$index]);
                  $foreignObject->{'add'.$relation['classFrom']}($rowObjects[$index]);
                }
                break;
            }

            if (self::DEBUG && self::DEBUG_POPULATE)
            {
              echo "\n";
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

  /**
   * Checks whether a primary key is consistent or not. Used to know if an object retrieved via a
   * left/right join (or equivalent) was null or not.
   *
   * @param mixed $key
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
   * Adds an object instance to the current objects array
   */
  protected function addObject($instance)
  {
    $this->objects[] = $instance;
  }


  protected function addObjectByClassName($className)
  {
    if (class_exists($className, true))
    {
      $this->addObject(new $className());
    }
    else
    {
      throw new Exception('sfPropelObjectPeerImpersonator::initialize: Unknown object class given "'.$className.'"');
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
