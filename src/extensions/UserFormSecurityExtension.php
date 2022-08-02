<?php

namespace DNADesign\UserDeniedForm\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;

/**
 * This extension removes the fields and actions from a form
 * when the submission rate limit has been exceeded
 */
class UserFormSecurityExtension extends Extension
{
    public function updateFormFields(&$fields)
    {
        $page = $this->owner->getController()->data();
        if ($page && $page->getFormIsDisabledAfterRateWasExceeded()) {
            $fields = new FieldList([
                LiteralField::create('warning', $page->DisabledFormMessage)
            ]);
        }
    }

    public function updateFormActions(&$actions)
    {
        $page = $this->owner->getController()->data();
        if ($page && $page->getFormIsDisabledAfterRateWasExceeded()) {
            $actions = new FieldList();
        }
    }
}
