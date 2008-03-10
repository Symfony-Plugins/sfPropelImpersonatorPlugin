<?php

class sfPropelFunctionObject
{
  /**
   * Value any getter will return
   */
  protected $callable;

  public function __construct($callable)
  {
    if (is_callable($callable))
    {
      $this->callable = $callable;
    }
    else
    {
      throw new Exception(__CLASS__.' constructor takes a callable as argument');
    }
  }

  /**
   * Magic getter/setter function
   */
  public function __call($method, $parameters)
  {
    $verb = substr($method, 0, 3);
    $field = sfInflector::underscore(substr($method, 3));

    switch ($verb)
    {
      case 'get':
        return call_user_func_array($this->callable, array_merge(array($field), $parameters));
      case 'set':
        return;
    }

    throw new sfException ('Unknown method called '.__CLASS__.'::'.$method.'()');
  }
}
