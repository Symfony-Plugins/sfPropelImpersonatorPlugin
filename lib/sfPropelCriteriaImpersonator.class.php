<?php
/**
 * sfPropelCriteriaImpersonator
 *
 * Helper for manipulating Criterias
 */
class sfPropelCriteriaImpersonator
{
  /**
   * Removes select columns not used by a doSelect method (ie every select_columns but
   * _not_ the AS columns. Criteria::clearSelectColumns clears both.
   *
   * @param Criteria $criteria
   *
   * @return Criteria
   */
  static public function clearSelectColumns(Criteria $criteria)
  {
    $criteria = clone $criteria;
    $asColumns = $criteria->getAsColumns();
    $criteria->clearSelectColumns()->clearOrderByColumns();

    // hack against criteria private policy
    foreach ($asColumns as $key => $value)
    {
      $criteria->addAsColumn($key, $value);
    }

    return $criteria;
  }

  /**
   * Returns an SQL equality using Criteria::IN or Criteria::EQUAL, depending on parameter type (array or int/string)
   *
   * @param &array $parameters
   * @param string $field
   * @param mixed $value
   * @param string $type
   *
   * @result string
   */
  static public function addMixedEquality(&$parameters, $field, $value, $type='int')
  {
    if (!is_array($parameters))
    {
      $parameters = array();
    }

    if (is_array($value))
    {
      if ($count=count($value))
      {
        if ($count==3&&$value[0]=='between')
        {
          $sql = '('.$field.' BETWEEN ? AND ?)';
          $parameters[] = array($type, array($value[1], $value[2]));
        }
        else
        {
          $sql = '('.$field.' '.Criteria::IN.' ('.substr(str_repeat('?,',$count),0,-1).'))';
          $parameters[] = array($type, $value);
        }
      }
      else
      {
        $sql = '(1<>1)';
      }
    }
    else
    {
      $sql = '('.$field.' '.Criteria::EQUAL.' ?)';
      $parameters[] = array($type, $value);
    }

    return $sql;
  }

  /**
   * Get the SQL query from a Criteria object. The query is applicable only if
   * Criteria contains select columns.
   *
   * @param Criteria $c
   * @param Connection $con
   *
   * @result string
   */
  static public function getSql(Criteria $c, $con = null)
  {
    $dbMap = Propel::getDatabaseMap($c->getDbName());

    if (null === $con)
    {
      $con = Propel::getConnection($c->getDbName());
    }

    $stmt = null;

    try
    {
      $params = array();
      $sql = BasePeer::createSelectSql($c, $params);

      $stmt = new sfCreolePreparedStatementCommonImpersonator($con, $sql);
      $stmt->setLimit($c->getLimit());
      $stmt->setOffset($c->getOffset());

      self::populateStmtValues($stmt, $params, $dbMap);
    }
    catch (Excpetion $e)
    {
      if ($stmt)
      {
        $stmt->close();
      }

      Propel::log($e->getMessage(), Propel::LOG_ERR);
      throw new PropelException($e);
    }

    return $stmt->getSql();
  }

  static public function populateStmtValues($stmt, $params, DatabaseMap $dbMap)
  {
    $i = 1;
    foreach($params as $param)
    {
      $tableName = $param['table'];
      $columnName = $param['column'];
      $value = $param['value'];

      if ($value === null)
      {
        $stmt->setNull($i++);
      }
      else
      {
        $cMap = $dbMap->getTable($tableName)->getColumn($columnName);
        $setter = 'set' . CreoleTypes::getAffix($cMap->getCreoleType());
        $stmt->$setter($i++, $value);
      }
    }
  }
}

/**
 * sfCreolePreparedStatementCommonImpersonator
 *
 * Dummy class to extends PreparedStatementCommon, and so be able to use the protected method replaceParams
 */
class sfCreolePreparedStatementCommonImpersonator extends PreparedStatementCommon implements PreparedStatement
{
  public function getSql()
  {
    $sql = $this->replaceParams();

    if ($this->limit > 0 || $this->offset > 0)
    {
      $this->conn->applyLimit($sql, $this->offset, $this->limit);
    }

    return $sql;
  }

  protected function escape($str)
  {
    return sfPropelImpersonatorEscaper::escape($str, $this->conn);
  }
}

class sfPropelImpersonatorEscaper
{
  static public function escape($str, $connection=null)
  {
    if (null === $connection)
    {
      $connection = Propel::getConnection();
    }
    
    switch (get_resource_type($resource=$connection->getResource()))
    {
      case 'mysql link':
        return mysql_real_escape_string($str, $resource);
      case 'pgsql link':
        return pg_escape_string($str);
      default:
        throw new sfException(__CLASS__.' does not implements esacaping for '.get_resource_type($resource));
    }  
  }
}
