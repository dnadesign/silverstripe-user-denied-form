<?php

namespace DNADesign\UserDeniedForm\Extensions;

use DNADesign\Elemental\TopPage\DataExtension;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\NumericField;
use SilverStripe\UserForms\Model\UserDefinedForm;

class SiteConfigUserDeniedFormExtension extends DataExtension
{
    private static $db = [
        'DefaultRateCount' => 'Int',
        'DefaultRateFrequency' => 'Int', //  number of seconds
        'DefaultDisabledFormMessage' => 'HTMLText',
        'DefaultDisabledNotificationEmail' => 'Varchar(255)'
    ];

    private static $defaults = [
        'DefaultRateCount' => 60,
        'DefaultRateFrequency' => 60,
        'DefaultDisabledFormMessage' => 'This form is temporarily disabled. Please try again later.'
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldsToTab('Root.Security', [
            HeaderField::create('UserDefinedForm', 'User Defined Form'),
            HeaderField::create('RateLimiting', 'Submission Rate Limiting', 3),
            FieldGroup::create(
                'Max submissions rate',
                NumericField::create('DefaultRateCount', 'Submissions'),
                DropdownField::create('DefaultRateFrequency', '', UserDefinedForm::config()->get('submission_rate_frequencies')),
            ),
            HTMLEditorField::create('DefaultDisabledFormMessage'),
            EmailField::create('DefaultDisabledNotificationEmail')
        ]);
    }
}
