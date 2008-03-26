<?php
class sfPropelImpersonatorI18nRelation extends sfPropelImpersonatorAbstractRelation
{
  protected $culture;

  public function __construct($index, $from, $fromIdx, $to, $toIdx, $local, $distant, array $parameters, $culture)
  {
    $this->culture = $culture;
    parent::__construct($index, $from, $fromIdx, $to, $toIdx, $local, $distant, $parameters, $culture);
  }

  public function link(array &$rowObjects, $isNewObject)
  {
    if (null !== $this->iTo)
    {
      $object = $rowObjects[$this->iTo];
      $i18nObject = $rowObjects[$this->index];

      $i18nObject->{'set'.$this->to}($object);
      $object->{'set'.$this->to.'I18nForCulture'}($i18nObject, $this->culture);

      return true;
    }

    return false;
  }
}
