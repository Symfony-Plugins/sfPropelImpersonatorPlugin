<?php
/**
 * sfPropelCriteriaImpersonator
 *
 * Helper for manipulating Criterias
 */
class sfPropelCriteriaImpersonator
{
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
    return $this->replaceParams();
  }

  protected function escape($str)
  {
    return mysql_real_escape_string($str, $this->conn->getResource());
  }
}
