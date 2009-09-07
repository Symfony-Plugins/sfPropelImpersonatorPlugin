<?php
class sfPropelImpersonatorOneToManyRelation extends sfPropelImpersonatorAbstractRelation
{
  private $foreignObjectInitialized = array();

  public function link(array &$rowObjects, $isNewObject)
  {
    if (null!==$this->iTo)
    {
      $foreignObject =& $rowObjects[$this->iTo];

      $relatedBy = (null===($relatedBy=$this->getParameter('to', 'related_by')))?'':'RelatedBy'.sfInflector::camelize($relatedBy);
      // @todo: bug correction, this does not work if related_by is used this way, it tries to call setFieldRelatedByFieldId
      // instead of setClassNameRelatedByFieldId, and sometimes (depending on order) it uses the wrong Field, as second one
      // override first one in relations

      // local *---- foreign (local object has one foreign object)
      $rowObjects[$this->index]->{'set'.$this->to.$relatedBy}($foreignObject);

      // foreign ----* local (foreign object has many local objects)
      // we have to check if there are already other objects, or if this is the first.

      if ($this->foreignObjectInitialized($foreignObject))
      {
        $foreignObject->{'init'.$this->from.'s'.$relatedBy}($rowObjects[$this->index]);
      }
      $foreignObject->{'add'.$this->from.$relatedBy}($rowObjects[$this->index]);

      return true;
    }
    return false;
  }

  protected function foreignObjectInitialized($object)
  {
    $objectHash = spl_object_hash($object);

    if (isset($this->foreignObjectInitialized[$objectHash]))
    {
      return false;
    }
    else
    {
      $this->foreignObjectInitialized[$objectHash] = true;
      return true;
    }
  }
}