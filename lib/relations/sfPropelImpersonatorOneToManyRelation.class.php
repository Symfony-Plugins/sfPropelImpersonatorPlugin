<?php
class sfPropelImpersonatorOneToManyRelation extends sfPropelImpersonatorAbstractRelation
{
  public function link(array &$rowObjects, $isNewObject)
  {
    if ($this->iTo)
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
      // @todo: find a way not to call initXxxXxxs() on every passes, but only on first addition for each object.

      $foreignObject->{'init'.$this->from.'s'.$relatedBy}($rowObjects[$this->index]);
      $foreignObject->{'add'.$this->from.$relatedBy}($rowObjects[$this->index]);

      return true;
    }

    return false;
  }
}
