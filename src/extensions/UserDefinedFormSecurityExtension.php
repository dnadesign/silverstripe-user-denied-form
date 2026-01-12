<?php

namespace DNADesign\UserDeniedForm\Extensions;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use UncleCheese\DisplayLogic\Forms\Wrapper;

class UserDefinedFormSecurityExtension extends Extension
{
    private static $submission_rate_limit_enabled = true;

    private static $reset_rate_limit_automatically = true;

    private static $submission_rate_frequencies = [
        '60' => 'per minute',
        '30' => 'per 30 seconds'
    ];

    private static $db = [
        'RateLimitEnabled' => 'Boolean',
        'RateLimitSettings' => 'Enum("Default, Custom")',
        'RateCount' => 'Int',
        'RateFrequency' => 'Int', //  number of seconds
        'DisabledFormMessage' => 'HTMLText',
        'DisabledFormNotificationEmail' => 'Varchar(255)',
        'RateLimitReachedOn' => 'Datetime'
    ];

    private static $defaults = [
        'RateLimitEnabled' => true
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        if (!$this->classHasRateLimitingEnabled()) {
            $fields->removeByName(array_keys($this->db));
            return;
        }

        // Remove auto-scaffolded fields to prevent duplicates
        $fields->removeByName([
            'RateCount',
            'RateFrequency',
            'DisabledFormMessage',
            'DisabledFormNotificationEmail'
        ]);

        $settingsOptions = $this->owner->dbObject('RateLimitSettings')->enumValues();
        $settingsOptions = [
            'Default' => sprintf('Default (%s)', $this->getDefaultRateAsString()),
            'Custom' => 'Custom'
        ];

        $fields->addFieldsToTab('Root.Security', [
            CheckboxField::create('RateLimitEnabled'),
            DropdownField::create('RateLimitSettings', 'Rate Limit Settings', $settingsOptions),
            $settings = Wrapper::create(
                FieldGroup::create(
                    'Max submissions rate',
                    NumericField::create('RateCount', 'Submissions'),
                    DropdownField::create('RateFrequency', '', $this->owner->config()->get('submission_rate_frequencies')),
                ),
                HTMLEditorField::create('DisabledFormMessage', 'Disabled Form Message'),
                EmailField::create('DisabledFormNotificationEmail', 'Notification Email')
            )
        ]);

        if ($this->owner->getFormIsDisabledAfterRateWasExceeded()) {
            $reachedOn = DatetimeField::create('RateLimitReachedOn')->setReadonly(true);
            // If form doesn't reset itself, provide a way to erase the reachOnDate to enable the form
            if ($this->owner->config()->get('reset_rate_limit_automatically') === false) {
                $reachedOn = TextField::create('RateLimitReachedOn')->setDescription('Erase the date to re-enable the form');
            }
            $fields->addFieldsToTab('Root.Security', $reachedOn);
            $fields->unshift(LiteralField::create('warning', '<p class="message error">Form is disabled after submission rate limit was reached.'));
        }

        $settings->displayIf('RateLimitEnabled')->isChecked()->andIf('RateLimitSettings')->isEqualTo('Custom')->end();
    }

    /**
     * This gives to opportunity to disable rate limiting
     * on certain subclasses if needed
     *
     * @return boolean
     */
    public function classHasRateLimitingEnabled() : bool
    {
        return $this->owner->config()->get('submission_rate_limit_enabled');
    }

    /**
     * Check that everything is set up before being able to
     * determine of the submission rate is exceeded
     *
     * @return boolean
     */
    public function shouldLimitSubmissionRate() : bool
    {
        return $this->owner->classHasRateLimitingEnabled()
                && $this->owner->RateLimitEnabled
                && $this->owner->getFinalRateCount()
                && $this->owner->getFinalRateFrequency();
    }

    /**
     * Return the relevant Rate Frequency
     *
     * @return int
     */
    public function getFinalRateFrequency() : int
    {
        if ($this->owner->RateLimitSettings === 'Custom' && $this->owner->RateFrequency) {
            return $this->owner->RateFrequency;
        }

        $config = SiteConfig::current_site_config();
        if ($config && $config->DefaultRateFrequency) {
            return $config->DefaultRateFrequency;
        }

        return SiteConfig::config()->get('defaults')['DefaultRateFrequency'];
    }

    /**
     * Return the relevant Rate Count
     *
     * @return int
     */
    public function getFinalRateCount() : int
    {
        if ($this->owner->RateLimitSettings === 'Custom' && $this->owner->RateCount) {
            return $this->owner->RateCount;
        }

        return $this->owner->getDefaultRateCount();
    }

    /**
     * Return rate count from site config
     * NOTE: split from getFinalRateCount so we can display it
     * in the RateLimitSettings dropdown
     *
     * @return int
     */
    public function getDefaultRateCount() : int
    {
        $config = SiteConfig::current_site_config();
        if ($config && $config->DefaultRateCount) {
            return $config->DefaultRateCount;
        }

        return SiteConfig::config()->get('defaults')['DefaultRateCount'];
    }

    /**
     * Return the email address to notify when the form is enabled or disabled
     *
     * @return string
     */
    public function getFinalNotificationEmail() : ?string
    {
        if ($this->owner->RateLimitSettings === 'Custom' && $this->owner->DisabledFormNotificationEmail) {
            return $this->owner->DisabledFormNotificationEmail;
        }

        $config = SiteConfig::current_site_config();
        if ($config && $config->DefaultDisabledNotificationEmail) {
            return $config->DefaultDisabledNotificationEmail;
        }

        return null;
    }

    /**
     * Return a readable rate: eg 60 per minute
     *
     * @return string
     */
    public function getDefaultRateAsString() : string
    {
        $count =  $this->owner->getDefaultRateCount();
        $freq = $this->owner->getFinalRateFrequency();
        $freqString = $this->owner->config()->get('submission_rate_frequencies')[$freq];

        return sprintf('%s %s', $count, $freqString);
    }

    /**
     * Return the number of submissions created during the selected period
     *
     * @return int
     */
    public function getSubmissionVolume() : int
    {
        $then = strtotime(sprintf('%s seconds ago', $this->owner->getFinalRateFrequency()));

        $submissions = SubmittedForm::get()->filter(['ParentID' => $this->owner->ID, 'Created:GreaterThan' => $then]);

        $volume = $submissions->count();

        $this->owner->extend('updateSubmissionVolume', $volume, $submissions);

        return $volume;
    }

    /**
     * Return whether the submission rate limit has been reached
     *
     * @return boolean
     */
    public function getThresholdIsExceeded() : bool
    {
        return $this->owner->getSubmissionVolume() > $this->owner->getFinalRateCount();
    }

    /**
     * Check whether the threshold has been reached on the past
     * TODO: check if a certain amount of time has past and the rate limit should be reset automatically
     *
     * @return boolean
     */
    public function getThresholdWasExceeded() : bool
    {
        $date = $this->owner->dbObject('RateLimitReachedOn');
        $exceededOnInPast = $date->getValue() && $date->InPast();

        $this->owner->extend('updateRateWasExceed', $exceededOnInPast);

        return $exceededOnInPast;
    }

    /**
     * Check if the form should be disabled
     *
     * @return true
     */
    public function getFormIsDisabledAfterRateWasExceeded() : bool
    {
        return $this->owner->shouldLimitSubmissionRate() && $this->owner->getThresholdWasExceeded();
    }

    /**
     * Check the volume of submission and set flag if necessary
     */
    public function checkRateLimitAfterLastSubmission()
    {
        if ($this->owner->shouldLimitSubmissionRate()) {
            if ($this->getThresholdWasExceeded() === false && $this->owner->getThresholdIsExceeded() === true) {
                $this->owner->RateLimitReachedOn = DBDatetime::now()->getValue();
                if ($this->owner->isPublished()) {
                    $this->owner->publishSingle();
                } else {
                    $this->owner->write();
                }
                $this->owner->notifyAfterRateLimitReached();
            }
        }
    }

    /**
     * Notify relevant people that the form has been disabled/enabled
     */
    public function notifyAfterRateLimitReached($disabled = true)
    {
        $message = sprintf(
            'Form %s (%s) has been %s after the submission rate limit has been %s.',
            $this->owner->Title,
            $this->owner->ID,
            $disabled ? 'disabled' : 're-enabled',
            $disabled ? 'reached' : 'lifted'
        );

        Injector::inst()->get(LoggerInterface::class)->warn($message);

        $emailAddress = $this->owner->getFinalNotificationEmail();
        if ($emailAddress && filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            try {
                $subject = sprintf(
                    '%s: Form %s (%s) has been %s',
                    SiteConfig::current_site_config()->Title,
                    $this->owner->Title,
                    $this->owner->ID,
                    $disabled ? 'disabled' : 're-enabled'
                );

                $email = new Email();
                $email->setSubject($subject);
                $email->setBody($message);
                $email->setTo($emailAddress);

                $this->owner->extend('updateNotificationEmail', $email, $disabled);

                $email->send();
            } catch (\Exception $e) {
                Injector::inst()->get(LoggerInterface::class)->warn($e->getMessage());
            }
        }
    }

    /**
     * Check that the last submission has been created
     * at least the same amount of seconds ago than the set frequency
     *
     * @return boolean
     */
    public function shouldResetRateLimit() : bool
    {
        $should = false;
        if ($this->owner->config()->get('reset_rate_limit_automatically') === true) {
            $lastSubmission = SubmittedForm::get()->filter('ParentID', $this->owner->ID)->last();
            if ($lastSubmission) {
                $secondsSinceLastSubmission = DB::query(sprintf("SELECT TIMESTAMPDIFF(SECOND, Created, NOW()) FROM %s WHERE ID = %s", SubmittedForm::config()->get('table_name'), $lastSubmission->ID))->value();
                if ($secondsSinceLastSubmission && $secondsSinceLastSubmission > $this->owner->RateFrequency) {
                    $should = true;
                }
            }
        }

        $this->owner->extend('updateShouldResetRateLimit', $should);

        return $should;
    }

    /**
     * Reset the RateLimitReachedOn
     * and notify admin
     */
    public function resetRateLimit()
    {
        $this->owner->RateLimitReachedOn = null;
        if ($this->owner->isPublished()) {
            $this->owner->publishSingle();
        } else {
            $this->owner->write();
        }

        $this->owner->notifyAfterRateLimitReached(false);
    }
}
