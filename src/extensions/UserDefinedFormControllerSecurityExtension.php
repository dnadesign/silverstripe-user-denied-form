<?php

namespace DNADesign\UserDeniedForm\Extensions;

use SilverStripe\Core\Extension;

class UserDefinedFormControllerSecurityExtension extends Extension
{
    public function onBeforeInit()
    {
        $form = $this->owner->data();
        if ($form && $form->getFormIsDisabledAfterRateWasExceeded() && $form->shouldResetRateLimit()) {
            $form->resetRateLimit();
        }
    }
}
