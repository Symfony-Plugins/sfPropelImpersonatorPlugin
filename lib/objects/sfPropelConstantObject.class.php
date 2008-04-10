<?php

class sfPropelConstantObject
{
  /**
   * Value any getter will return
   */
  protected $value;

  /**
   * Whether or not setters will change $this->value
   */
  protected $ignoreSetterFlag;

  public function __construct($value, $ignoreSetterFlag=true)
  {
    $this->value = $value;
    $this->ignoreSetterFlag = $ignoreSetterFlag;
  }

  /**
   * Magic getter/setter function
   */
  public function __call($method, $parameters)
  {
    $verb = substr($method, 0, 3);

    switch ($verb)
    {
      case 'get':
        return $this->value;
      case 'set':
        if (!$this->ignoreSetterFlag)
        {
          $this->value = $parameters[0];
        }
        return;
    }

    throw new sfException ('Unknown method called '.__CLASS__.'::'.$method.'()');
  }
}
