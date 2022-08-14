<?php

namespace DNADesign\UserDeniedForm\Extensions;

use SilverStripe\Core\Extension;

class UserDefinedFormControllerSecurityExtension extends Extension
{
    public function onBeforeInit()
    {
        $form = $this->owner->data();
        if ($form && $form->hasMethod('getFormIsDisabledAfterRateWasExceeded') && $form->hasMethod('shouldResetRateLimit')) {
            if ($form->getFormIsDisabledAfterRateWasExceeded() && $form->shouldResetRateLimit()) {
                $form->resetRateLimit();
            }
        }
    }
}
