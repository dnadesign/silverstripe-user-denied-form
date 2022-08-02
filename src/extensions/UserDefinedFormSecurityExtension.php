<?php

namespace DNADesign\UserDeniedForm\Extensions;

use DateTime;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use UncleCheese\DisplayLogic\Forms\Wrapper;

class UserDefinedFormSecurityExtension extends DataExtension
{
    private static $submission_rate_limit_enabled = true;

    private static $reset_rate_limit_automatically = true;

    private static $submission_rate_frequencies = [
        '60' => 'per minute',
        '30' => 'per 30 seconds'
    ];

    private static $db = [
        'RateLimitEnabled' => 'Boolean',
        'RateLimitReachedOn' => 'Datetime',
        'RateCount' => 'Int',
        'RateFrequency' => 'Int', //  number of seconds
        'DisabledFormMessage' => 'HTMLText'
    ];

    private static $defaults = [
        'RateLimitEnabled' => true,
        'RateCount' => 60,
        'RateFrequency' => 60,
        'DisabledFormMessage' => 'This form is temporarily disabled. Please try again later.'
    ];

    public function updateCMSFields(FieldList $fields)
    {
        if (!$this->classHasRateLimitingEnabled()) {
            $fields->removeByName(array_keys($this->db));
            return;
        }

        $fields->addFieldsToTab('Root.Security', [
            CheckboxField::create('RateLimitEnabled'),
            $frequency = Wrapper::create(
                FieldGroup::create(
                    'Max submissions rate',
                    NumericField::create('RateCount', 'Submissions'),
                    DropdownField::create('RateFrequency', '', $this->owner->config()->get('submission_rate_frequencies')),
                ),
                HTMLEditorField::create('DisabledFormMessage')
            )
        ]);

        if ($this->owner->getFormIsDisabledAfterRateWasExceeded()) {
            $frequency->push(DatetimeField::create('RateLimitReachedOn')->setReadonly(true));
            $fields->unshift(LiteralField::create('warning', '<p class="message error">Form is disabled after submission rate limit was reached.'));
        }

        $frequency->displayIf('RateLimitEnabled')->isChecked()->end();
    }

    public function classHasRateLimitingEnabled() : bool
    {
        return $this->owner->config()->get('submission_rate_limit_enabled');
    }

    public function shouldLimitSubmissionRate() : bool
    {
        return $this->owner->classHasRateLimitingEnabled()
                && $this->owner->RateLimitEnabled
                && $this->owner->RateCount
                && $this->owner->RateFrequency;
    }

    /**
     * Return the number of submissions during the selected period
     *
     * @return int
     */
    public function getSubmissionVolume() : int
    {
        $then = strtotime(sprintf('%s seconds ago', $this->owner->RateFrequency));

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
    public function getRateIsExceeded() : bool
    {
        return $this->owner->getSubmissionVolume() > $this->owner->RateCount;
    }

    /**
     * Check if the form should be disabled
     *
     * @return true
     */
    public function getFormIsDisabledAfterRateWasExceeded() : bool
    {
        return $this->owner->shouldLimitSubmissionRate() && $this->owner->getRateWasExceeded();
    }

    /**
     * Check whether the threshold has been reached on the past
     * TODO: check if a certain amount of time has past and the rate limit should be reset automatically
     *
     * @return boolean
     */
    public function getRateWasExceeded() : bool
    {
        $date = $this->owner->dbObject('RateLimitReachedOn');
        $exceededOnInPast = $date->getValue() && $date->InPast();

        $this->owner->extend('updateRateWasExceed', $exceededOnInPast);

        return $exceededOnInPast;
    }

    /**
     * Check the volume of submission and set flag if necessary
     */
    public function checkRateLimitAfterLastSubmission()
    {
        if ($this->owner->shouldLimitSubmissionRate()) {
            if (!$this->getRateWasExceeded() && $this->owner->getRateIsExceeded()) {
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
     * Notify relevant people that the form has been disabled
     * TODO: send an email?
     */
    public function notifyAfterRateLimitReached()
    {
        $message = sprintf('Form %s (%s) has been disabled after the submission rate limit has been reached.', $this->owner->Title, $this->owner->ID);
        Injector::inst()->get(LoggerInterface::class)->warn($message);
    }

    public function notifyAfterRateLimitLifted()
    {
        $message = sprintf('Form %s (%s) has been enabled after the submission rate limit has been lifted.', $this->owner->Title, $this->owner->ID);
        Injector::inst()->get(LoggerInterface::class)->warn($message);
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

        $this->owner->notifyAfterRateLimitLifted();
    }
}
