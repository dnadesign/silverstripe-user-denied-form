<?php

namespace DNADesign\UserDeniedForm\Extensions;

use SilverStripe\Core\Extension;

class SubmittedFormSecurityExtension extends Extension
{
    public function updateAfterProcess($emailData, $attachments)
    {
        $formPage = $this->owner->Parent();
        if ($formPage && $formPage->exists()) {
            $formPage->checkRateLimitAfterLastSubmission();
        }
    }
}
