<?php

class sfPropelCriteriaImpersonator
{
  public static function getSql(Criteria $c, $con = null)
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


  public static function populateStmtValues($stmt, $params, DatabaseMap $dbMap)
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
