<?php
/**
 * fakePropelObject
 *
 * class to impersonate a propel object
 *
 * @package veolys
 * @subpackage lib
 * @author Romain Dorgueil <romain.dorgueil@sensio.net>
 * @version SVN: $Id$
 */
class sfPropelObjectImpersonator
{
  protected
    $columns;

  public function __construct($columns)
  {
    $this->columns = $columns;
  }

  public function getPrimaryKey()
  {
    return 0;
  }

  public function hydrate(ResultSet $rs, $startcol = 1)
  {
    try
    {
      foreach($this->columns as $_array)
      {
        list($type, $name) = $_array;
        $this->$name = $rs->{'get'.ucfirst($type)}($startcol);
        $startcol++;
      }

      return $startcol;
    }
    catch (Exception $e)
    {
      throw new PropelException("Error populating Impersonator object", $e);
    }
  }

  public function __call($method, $parameters)
  {
    $verb = substr($method, 0, 3);
    $field = sfInflector::underscore(substr($method, 3));

    if (property_exists($this, $field))
    {
      switch ($verb)
      {
        case 'get':
          return $this->$field;
        case 'set':
          $this->$field = $parameters[0];
          return;
      }
    }

    throw new sfException ('Unknown method called '.__CLASS__.'::'.$method.'()');
  }

  public function getPeer()
  {
    return null;
  }

  public function __toString()
  {
    return $this->name;
  }
}

