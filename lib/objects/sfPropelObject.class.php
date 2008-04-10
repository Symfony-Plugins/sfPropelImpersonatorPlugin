<?php
/**
 * sfPropelObject
 *
 * class to impersonate a propel object
 *
 * @package veolys
 * @subpackage lib
 * @author Romain Dorgueil <romain.dorgueil@sensio.net>
 * @version SVN: $Id$
 */
class sfPropelObject
{
  protected
    $columns;

  public function __construct($columns)
  {
    foreach($columns as $index=>$column)
    {
      if (is_array($column) && count($column)>=2)
      {
        $columns[$index][2] = trim(strtolower(preg_replace('/[^a-z]/i', '_', $column[1])), '_');
      }
      else
      {
        throw new InvalidArgumentException('sfPropelObject constructor waits for a list of 2 items-array (at least) arguments.');
      }
    }

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
        list($type, $field, $name) = $_array;
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

  public function addSelectColumns(Criteria &$c)
  {
    foreach ($this->columns as $index => $column)
    {
      $c->addAsColumn($column[2], $column[1]);
    }
  }

  public function getPeer()
  {
    return null;
  }

  public function getColumns()
  {
    return $this->columns;
  }

  public function __toString()
  {
    return $this->name;
  }
}

