<?php

namespace SilverStripe\UserForms\Model;

use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\LabelField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\SegmentField;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\ValidationException;
use SilverStripe\UserForms\Extension\UserFormFieldEditorExtension;
use SilverStripe\UserForms\Model\UserDefinedForm;
use SilverStripe\UserForms\Model\EditableCustomRule;
use SilverStripe\UserForms\Model\EditableFormField\EditableFieldGroup;
use SilverStripe\UserForms\Model\EditableFormField\EditableFieldGroupEnd;
use SilverStripe\UserForms\Model\EditableFormField\EditableFormStep;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;
use SilverStripe\UserForms\Modifier\DisambiguationSegmentFieldModifier;
use SilverStripe\UserForms\Modifier\UnderscoreSegmentFieldModifier;
use SilverStripe\Versioned\Versioned;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;

/**
 * Represents the base class of a editable form field
 * object like {@link EditableTextField}.
 *
 * @package userforms
 *
 * @property string $Name
 * @property string $Title
 * @property string $Default
 * @property int $Sort
 * @property bool $Required
 * @property string $CustomErrorMessage
 * @property boolean $ShowOnLoad
 * @property string $DisplayRulesConjunction
 * @method UserDefinedForm Parent() Parent page
 * @method DataList DisplayRules() List of EditableCustomRule objects
 * @mixin Versioned
 */
class EditableFormField extends DataObject
{
    /**
     * Set to true to hide from class selector
     *
     * @config
     * @var bool
     */
    private static $hidden = false;

    /**
     * Define this field as abstract (not inherited)
     *
     * @config
     * @var bool
     */
    private static $abstract = true;

    /**
     * Flag this field type as non-data (e.g. literal, header, html)
     *
     * @config
     * @var bool
     */
    private static $literal = false;

    /**
     * Default sort order
     *
     * @config
     * @var string
     */
    private static $default_sort = '"Sort"';

    /**
     * A list of CSS classes that can be added
     *
     * @var array
     */
    public static $allowed_css = [];

    /**
     * Set this to true to enable placeholder field for any given class
     * @config
     * @var bool
     */
    private static $has_placeholder = false;

    /**
     * @config
     * @var array
     */
    private static $summary_fields = [
        'Title'
    ];

    /**
     * @config
     * @var array
     */
    private static $db = [
        'Name' => 'Varchar',
        'Title' => 'Varchar(255)',
        'Default' => 'Varchar(255)',
        'Sort' => 'Int',
        'Required' => 'Boolean',
        'CustomErrorMessage' => 'Varchar(255)',

        'CustomRules' => 'Text', // @deprecated from 2.0
        'CustomSettings' => 'Text', // @deprecated from 2.0
        'Migrated' => 'Boolean', // set to true when migrated

        'ExtraClass' => 'Text', // from CustomSettings
        'RightTitle' => 'Varchar(255)', // from CustomSettings
        'ShowOnLoad' => 'Boolean(1)', // from CustomSettings
        'ShowInSummary' => 'Boolean',
        'Placeholder' => 'Varchar(255)',
        'DisplayRulesConjunction' => 'Enum("And,Or","Or")',
    ];

    private static $table_name = 'EditableFormField';


    private static $defaults = [
        'ShowOnLoad' => true,
    ];


    /**
     * @config
     * @var array
     */
    private static $has_one = [
        'Parent' => UserDefinedForm::class,
    ];

    /**
     * Built in extensions required
     *
     * @config
     * @var array
     */
    private static $extensions = [
        Versioned::class . "('Stage', 'Live')"
    ];

    /**
     * @config
     * @var array
     */
    private static $has_many = [
        'DisplayRules' => EditableCustomRule::class . '.Parent'
    ];

    /**
     * @var bool
     */
    protected $readonly;

    /**
     * Property holds the JS event which gets fired for this type of element
     *
     * @var string
     */
    protected $jsEventHandler = 'change';

    /**
     * Returns the jsEventHandler property for the current object. Bearing in mind it could've been overridden.
     * @return string
     */
    public function getJsEventHandler()
    {
        return $this->jsEventHandler;
    }

    /**
     * Set the visibility of an individual form field
     *
     * @param bool
     */
    public function setReadonly($readonly = true)
    {
        $this->readonly = $readonly;
    }

    /**
     * Returns whether this field is readonly
     *
     * @return bool
     */
    private function isReadonly()
    {
        return $this->readonly;
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = FieldList::create(TabSet::create('Root'));

        // Main tab
        $fields->addFieldsToTab(
            'Root.Main',
            [
                ReadonlyField::create(
                    'Type',
                    _t(__CLASS__.'.TYPE', 'Type'),
                    $this->i18n_singular_name()
                ),
                CheckboxField::create('ShowInSummary', _t(__CLASS__.'.SHOWINSUMMARY', 'Show in summary gridfield')),
                LiteralField::create(
                    'MergeField',
                    _t(
                        __CLASS__.'.MERGEFIELDNAME',
                        '<div class="field readonly">' .
                            '<label class="left">' . _t(__CLASS__.'.MERGEFIELDNAME', 'Merge field') . '</label>' .
                            '<div class="middleColumn">' .
                                '<span class="readonly">$' . $this->Name . '</span>' .
                            '</div>' .
                        '</div>'
                    )
                ),
                TextField::create('Title', _t(__CLASS__.'.TITLE', 'Title')),
                TextField::create('Default', _t(__CLASS__.'.DEFAULT', 'Default value')),
                TextField::create('RightTitle', _t(__CLASS__.'.RIGHTTITLE', 'Right title')),
                SegmentField::create('Name', _t(__CLASS__.'.NAME', 'Name'))->setModifiers([
                    UnderscoreSegmentFieldModifier::create()->setDefault('FieldName'),
                    DisambiguationSegmentFieldModifier::create(),
                ])->setPreview($this->Name)
            ]
        );
        $fields->fieldByName('Root.Main')->setTitle(_t('SilverStripe\\CMS\\Model\\SiteTree.TABMAIN', 'Main'));

        // Custom settings
        if (!empty(self::$allowed_css)) {
            $cssList = [];
            foreach (self::$allowed_css as $k => $v) {
                if (!is_array($v)) {
                    $cssList[$k]=$v;
                } elseif ($k === $this->ClassName) {
                    $cssList = array_merge($cssList, $v);
                }
            }

            $fields->addFieldToTab('Root.Main',
                DropdownField::create(
                    'ExtraClass',
                    _t(__CLASS__.'.EXTRACLASS_TITLE', 'Extra Styling/Layout'),
                    $cssList
                )->setDescription(_t(
                    __CLASS__.'.EXTRACLASS_SELECT',
                    'Select from the list of allowed styles'
                ))
            );
        } else {
            $fields->addFieldToTab('Root.Main',
                TextField::create(
                    'ExtraClass',
                    _t(__CLASS__.'.EXTRACLASS_Title', 'Extra CSS classes')
                )->setDescription(_t(
                    __CLASS__.'.EXTRACLASS_MULTIPLE',
                    'Separate each CSS class with a single space'
                ))
            );
        }

        // Validation
        $validationFields = $this->getFieldValidationOptions();
        if ($validationFields && $validationFields->count()) {
            $fields->addFieldsToTab('Root.Validation', $validationFields);
            $fields->fieldByName('Root.Validation')->setTitle(_t(__CLASS__.'.VALIDATION', 'Validation'));
        }

        // Add display rule fields
        $displayFields = $this->getDisplayRuleFields();
        if ($displayFields && $displayFields->count()) {
            $fields->addFieldsToTab('Root.DisplayRules', $displayFields);
        }

        // Placeholder
        if ($this->config()->has_placeholder) {
            $fields->addFieldToTab(
                'Root.Main',
                TextField::create(
                    'Placeholder',
                    _t(__CLASS__.'.PLACEHOLDER', 'Placeholder')
                )
            );
        }

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    /**
     * Return fields to display on the 'Display Rules' tab
     *
     * @return FieldList
     */
    protected function getDisplayRuleFields()
    {
        // Check display rules
        if ($this->Required) {
            return new FieldList(
                LabelField::create(
                    _t(
                    __CLASS__.'.DISPLAY_RULES_DISABLED',
                    'Display rules are not enabled for required fields. Please uncheck "Is this field Required?" under "Validation" to re-enable.'))
                  ->addExtraClass('message warning'));
        }

        $allowedClasses = array_keys($this->getEditableFieldClasses(false));
        $editableColumns = new GridFieldEditableColumns();
        $editableColumns->setDisplayFields([
            'ConditionFieldID' => function ($record, $column, $grid) use ($allowedClasses) {
                    return DropdownField::create($column, '', EditableFormField::get()->filter([
                            'ParentID' => $this->ParentID,
                            'ClassName' => $allowedClasses,
                        ])->exclude([
                            'ID' => $this->ID,
                        ])->map('ID', 'Title'));
            },
            'ConditionOption' => function ($record, $column, $grid) {
                $options = Config::inst()->get(EditableCustomRule::class, 'condition_options');

                return DropdownField::create($column, '', $options);
            },
            'FieldValue' => function ($record, $column, $grid) {
                return TextField::create($column);
            }
        ]);

        // Custom rules
        $customRulesConfig = GridFieldConfig::create()
            ->addComponents(
                $editableColumns,
                new GridFieldButtonRow(),
                new GridFieldToolbarHeader(),
                new GridFieldAddNewInlineButton(),
                new GridFieldDeleteAction()
            );

        return new FieldList(
            DropdownField::create('ShowOnLoad',
                _t(__CLASS__.'.INITIALVISIBILITY', 'Initial visibility'),
                [
                    1 => 'Show',
                    0 => 'Hide',
                ]
            ),
            DropdownField::create('DisplayRulesConjunction',
                _t(__CLASS__.'.DISPLAYIF', 'Toggle visibility when'),
                [
                    'Or'  => _t('SilverStripe\\UserForms\\Model\\UserDefinedForm.SENDIFOR', 'Any conditions are true'),
                    'And' => _t('SilverStripe\\UserForms\\Model\\UserDefinedForm.SENDIFAND', 'All conditions are true'),
                ]
            ),
            GridField::create(
                'DisplayRules',
                _t(__CLASS__.'.CUSTOMRULES', 'Custom Rules'),
                $this->DisplayRules(),
                $customRulesConfig
            )
        );
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // Set a field name.
        if (!$this->Name) {
            // New random name
            $this->Name = $this->generateName();
        } elseif ($this->Name === 'Field') {
            throw new ValidationException('Field name cannot be "Field"');
        }

        if (!$this->Sort && $this->ParentID) {
            $parentID = $this->ParentID;
            $this->Sort = EditableFormField::get()
                ->filter('ParentID', $parentID)
                ->max('Sort') + 1;
        }
    }

    /**
     * Generate a new non-conflicting Name value
     *
     * @return string
     */
    protected function generateName()
    {
        do {
            // Generate a new random name after this class
            $class = get_class($this);
            $entropy = substr(sha1(uniqid()), 0, 5);
            $name = "{$class}_{$entropy}";

            // Check if it conflicts
            $exists = EditableFormField::get()->filter('Name', $name)->count() > 0;
        } while ($exists);
        return $name;
    }

    /**
     * Flag indicating that this field will set its own error message via data-msg='' attributes
     *
     * @return bool
     */
    public function getSetsOwnError()
    {
        return false;
    }

    /**
     * Return whether a user can delete this form field
     * based on whether they can edit the page
     *
     * @param Member $member
     * @return bool
     */
    public function canDelete($member = null)
    {
        return $this->canEdit($member);
    }

    /**
     * Return whether a user can edit this form field
     * based on whether they can edit the page
     *
     * @param Member $member
     * @return bool
     */
    public function canEdit($member = null)
    {
        $parent = $this->Parent();
        if ($parent && $parent->exists()) {
            return $parent->canEdit($member) && !$this->isReadonly();
        } elseif (!$this->exists() && Controller::has_curr()) {
            // This is for GridFieldOrderableRows support as it checks edit permissions on
            // singleton of the class. Allows editing of User Defined Form pages by
            // 'Content Authors' and those with permission to edit the UDF page. (ie. CanEditType/EditorGroups)
            // This is to restore User Forms 2.x backwards compatibility.
            $controller = Controller::curr();
            if ($controller && $controller instanceof CMSPageEditController) {
                $parent = $controller->getRecord($controller->currentPageID());
                // Only allow this behaviour on pages using UserFormFieldEditorExtension, such
                // as UserDefinedForm page type.
                if ($parent && $parent->hasExtension(UserFormFieldEditorExtension::class)) {
                    return $parent->canEdit($member);
                }
            }
        }

        // Fallback to secure admin permissions
        return parent::canEdit($member);
    }

    /**
     * Return whether a user can view this form field
     * based on whether they can view the page, regardless of the ReadOnly status of the field
     *
     * @param Member $member
     * @return bool
     */
    public function canView($member = null)
    {
        $parent = $this->Parent();
        if ($parent && $parent->exists()) {
            return $parent->canView($member);
        }

        return true;
    }

    /**
     * Return whether a user can create an object of this type
     *
     * @param Member $member
     * @param array $context Virtual parameter to allow context to be passed in to check
     * @return bool
     */
    public function canCreate($member = null, $context = [])
    {
        // Check parent page
        $parent = $this->getCanCreateContext(func_get_args());
        if ($parent) {
            return $parent->canEdit($member);
        }

        // Fall back to secure admin permissions
        return parent::canCreate($member);
    }

    /**
     * Helper method to check the parent for this object
     *
     * @param array $args List of arguments passed to canCreate
     * @return SiteTree Parent page instance
     */
    protected function getCanCreateContext($args)
    {
        // Inspect second parameter to canCreate for a 'Parent' context
        if (isset($args[1]['Parent'])) {
            return $args[1]['Parent'];
        }
        // Hack in currently edited page if context is missing
        if (Controller::has_curr() && Controller::curr() instanceof CMSMain) {
            return Controller::curr()->currentPage();
        }

        // No page being edited
        return null;
    }

    /**
     * Check if can publish
     *
     * @param Member $member
     * @return bool
     */
    public function canPublish($member = null)
    {
        return $this->canEdit($member);
    }

    /**
     * Check if can unpublish
     *
     * @param Member $member
     * @return bool
     */
    public function canUnpublish($member = null)
    {
        return $this->canDelete($member);
    }

    /**
     * Publish this Form Field to the live site
     *
     * Wrapper for the {@link Versioned} publish function
     *
     * @param string $fromStage
     * @param string $toStage
     * @param bool $createNewVersion
     */
    public function doPublish($fromStage, $toStage, $createNewVersion = false)
    {
        $this->publish($fromStage, $toStage, $createNewVersion);
        $this->publishRules($fromStage, $toStage, $createNewVersion);
    }

    /**
     * Publish all field rules
     *
     * @param string $fromStage
     * @param string $toStage
     * @param bool $createNewVersion
     */
    protected function publishRules($fromStage, $toStage, $createNewVersion)
    {
        $seenRuleIDs = [];

        // Don't forget to publish the related custom rules...
        foreach ($this->DisplayRules() as $rule) {
            $seenRuleIDs[] = $rule->ID;
            $rule->doPublish($fromStage, $toStage, $createNewVersion);
            $rule->destroy();
        }

        // remove any orphans from the "fromStage"
        $rules = Versioned::get_by_stage(EditableCustomRule::class, $toStage)
            ->filter('ParentID', $this->ID);

        if (!empty($seenRuleIDs)) {
            $rules = $rules->exclude('ID', $seenRuleIDs);
        }

        foreach ($rules as $rule) {
            $rule->deleteFromStage($toStage);
        }
    }

    /**
     * Delete this field from a given stage
     *
     * Wrapper for the {@link Versioned} deleteFromStage function
     */
    public function doDeleteFromStage($stage)
    {
        // Remove custom rules in this stage
        $rules = Versioned::get_by_stage(EditableCustomRule::class, $stage)
            ->filter('ParentID', $this->ID);
        foreach ($rules as $rule) {
            $rule->deleteFromStage($stage);
        }

        // Remove record
        $this->deleteFromStage($stage);
    }

    /**
     * checks whether record is new, copied from SiteTree
     */
    public function isNew()
    {
        if (empty($this->ID)) {
            return true;
        }

        if (is_numeric($this->ID)) {
            return false;
        }

        return stripos($this->ID, 'new') === 0;
    }

    /**
     * checks if records is changed on stage
     * @return boolean
     */
    public function getIsModifiedOnStage()
    {
        // new unsaved fields could be never be published
        if ($this->isNew()) {
            return false;
        }

        $stageVersion = Versioned::get_versionnumber_by_stage(EditableFormField::class, 'Stage', $this->ID);
        $liveVersion = Versioned::get_versionnumber_by_stage(EditableFormField::class, 'Live', $this->ID);

        return ($stageVersion && $stageVersion != $liveVersion);
    }

    /**
     * @deprecated since version 4.0
     */
    public function getSettings()
    {
        Deprecation::notice('4.0', 'getSettings is deprecated');
        return (!empty($this->CustomSettings)) ? unserialize($this->CustomSettings) : array();
    }

    /**
     * @deprecated since version 4.0
     */
    public function setSettings($settings = array())
    {
        Deprecation::notice('4.0', 'setSettings is deprecated');
        $this->CustomSettings = serialize($settings);
    }

    /**
     * @deprecated since version 4.0
     */
    public function setSetting($key, $value)
    {
        Deprecation::notice('4.0', "setSetting({$key}) is deprecated");
        $settings = $this->getSettings();
        $settings[$key] = $value;

        $this->setSettings($settings);
    }

    /**
     * Set the allowed css classes for the extraClass custom setting
     *
     * @param array $allowed The permissible CSS classes to add
     */
    public function setAllowedCss(array $allowed)
    {
        if (is_array($allowed)) {
            foreach ($allowed as $k => $v) {
                self::$allowed_css[$k] = (!is_null($v)) ? $v : $k;
            }
        }
    }

    /**
     * @deprecated since version 4.0
     */
    public function getSetting($setting)
    {
        Deprecation::notice("4.0", "getSetting({$setting}) is deprecated");

        $settings = $this->getSettings();
        if (isset($settings) && count($settings) > 0) {
            if (isset($settings[$setting])) {
                return $settings[$setting];
            }
        }
        return '';
    }

    /**
     * Get the path to the icon for this field type, relative to the site root.
     *
     * @return string
     */
    public function getIcon()
    {
        return USERFORMS_DIR . '/images/' . strtolower($this->class) . '.png';
    }

    /**
     * Return whether or not this field has addable options
     * such as a dropdown field or radio set
     *
     * @return bool
     */
    public function getHasAddableOptions()
    {
        return false;
    }

    /**
     * Return whether or not this field needs to show the extra
     * options dropdown list
     *
     * @return bool
     */
    public function showExtraOptions()
    {
        return true;
    }

    /**
     * Returns the Title for rendering in the front-end (with XML values escaped)
     *
     * @return string
     */
    public function getEscapedTitle()
    {
        return Convert::raw2xml($this->Title);
    }

    /**
     * Find the numeric indicator (1.1.2) that represents it's nesting value
     *
     * Only useful for fields attached to a current page, and that contain other fields such as pages
     * or groups
     *
     * @return string
     */
    public function getFieldNumber()
    {
        // Check if exists
        if (!$this->exists()) {
            return null;
        }
        // Check parent
        $form = $this->Parent();
        if (!$form || !$form->exists() || !($fields = $form->Fields())) {
            return null;
        }

        $prior = 0; // Number of prior group at this level
        $stack = []; // Current stack of nested groups, where the top level = the page
        foreach ($fields->map('ID', 'ClassName') as $id => $className) {
            if ($className === EditableFormStep::class) {
                $priorPage = empty($stack) ? $prior : $stack[0];
                $stack = array($priorPage + 1);
                $prior = 0;
            } elseif ($className === EditableFieldGroup::class) {
                $stack[] = $prior + 1;
                $prior = 0;
            } elseif ($className === EditableFieldGroupEnd::class) {
                $prior = array_pop($stack);
            }
            if ($id == $this->ID) {
                return implode('.', $stack);
            }
        }
        return null;
    }

    public function getCMSTitle()
    {
        return $this->i18n_singular_name() . ' (' . $this->Title . ')';
    }

    /**
     * @deprecated since version 4.0
     */
    public function getFieldName($field = false)
    {
        Deprecation::notice('4.0', "getFieldName({$field}) is deprecated");
        return ($field) ? "Fields[".$this->ID."][".$field."]" : "Fields[".$this->ID."]";
    }

    /**
     * @deprecated since version 4.0
     */
    public function getSettingName($field)
    {
        Deprecation::notice('4.0', "getSettingName({$field}) is deprecated");
        $name = $this->getFieldName('CustomSettings');

        return $name . '[' . $field .']';
    }

    /**
     * Append custom validation fields to the default 'Validation'
     * section in the editable options view
     *
     * @return FieldList
     */
    public function getFieldValidationOptions()
    {
        $fields = new FieldList(
            CheckboxField::create('Required', _t(__CLASS__.'.REQUIRED', 'Is this field Required?'))
                ->setDescription(_t(__CLASS__.'.REQUIRED_DESCRIPTION', 'Please note that conditional fields can\'t be required')),
            TextField::create('CustomErrorMessage', _t(__CLASS__.'.CUSTOMERROR', 'Custom Error Message'))
        );

        $this->extend('updateFieldValidationOptions', $fields);

        return $fields;
    }

    /**
     * Return a FormField to appear on the front end. Implement on
     * your subclass.
     *
     * @return FormField
     */
    public function getFormField()
    {
        user_error("Please implement a getFormField() on your EditableFormClass ". $this->ClassName, E_USER_ERROR);
    }

    /**
     * Updates a formfield with extensions
     *
     * @param FormField $field
     */
    public function doUpdateFormField($field)
    {
        $this->extend('beforeUpdateFormField', $field);
        $this->updateFormField($field);
        $this->extend('afterUpdateFormField', $field);
    }

    /**
     * Updates a formfield with the additional metadata specified by this field
     *
     * @param FormField $field
     */
    protected function updateFormField($field)
    {
        // set the error / formatting messages
        $field->setCustomValidationMessage($this->getErrorMessage()->RAW());

        // set the right title on this field
        if ($this->RightTitle) {
            // Since this field expects raw html, safely escape the user data prior
            $field->setRightTitle(Convert::raw2xml($this->RightTitle));
        }

        // if this field is required add some
        if ($this->Required) {
            // Required validation can conflict so add the Required validation messages as input attributes
            $errorMessage = $this->getErrorMessage()->HTML();
            $field->addExtraClass('requiredField');
            $field->setAttribute('data-rule-required', 'true');
            $field->setAttribute('data-msg-required', $errorMessage);

            if ($identifier = UserDefinedForm::config()->required_identifier) {
                $title = $field->Title() . " <span class='required-identifier'>". $identifier . "</span>";
                $field->setTitle($title);
            }
        }

        // if this field has an extra class
        if ($this->ExtraClass) {
            $field->addExtraClass($this->ExtraClass);
        }

        // if ShowOnLoad is false hide the field
        if (!$this->ShowOnLoad) {
            $field->addExtraClass($this->ShowOnLoadNice());
        }

        // if this field has a placeholder
        if ($this->Placeholder) {
            $field->setAttribute('placeholder', $this->Placeholder);
        }
    }

    /**
     * Return the instance of the submission field class
     *
     * @return SubmittedFormField
     */
    public function getSubmittedFormField()
    {
        return SubmittedFormField::create();
    }


    /**
     * Show this form field (and its related value) in the reports and in emails.
     *
     * @return bool
     */
    public function showInReports()
    {
        return true;
    }

    /**
     * Return the error message for this field. Either uses the custom
     * one (if provided) or the default SilverStripe message
     *
     * @return Varchar
     */
    public function getErrorMessage()
    {
        $title = strip_tags("'". ($this->Title ? $this->Title : $this->Name) . "'");
        $standard = sprintf(_t('SilverStripe\\Forms\\Form.FIELDISREQUIRED', '%s is required').'.', $title);

        // only use CustomErrorMessage if it has a non empty value
        $errorMessage = (!empty($this->CustomErrorMessage)) ? $this->CustomErrorMessage : $standard;

        return DBField::create_field('Varchar', $errorMessage);
    }

    /**
     * Invoked by UserFormUpgradeService to migrate settings specific to this field from CustomSettings
     * to the field proper
     *
     * @param array $data Unserialised data
     */
    public function migrateSettings($data)
    {
        // Map 'Show' / 'Hide' to boolean
        if (isset($data['ShowOnLoad'])) {
            $this->ShowOnLoad = $data['ShowOnLoad'] === '' || ($data['ShowOnLoad'] && $data['ShowOnLoad'] !== 'Hide');
            unset($data['ShowOnLoad']);
        }

        // Migrate all other settings
        foreach ($data as $key => $value) {
            if ($this->hasField($key)) {
                $this->setField($key, $value);
            }
        }
    }

    /**
     * Get the formfield to use when editing this inline in gridfield
     *
     * @param string $column name of column
     * @param array $fieldClasses List of allowed classnames if this formfield has a selectable class
     * @return FormField
     */
    public function getInlineClassnameField($column, $fieldClasses)
    {
        return DropdownField::create($column, false, $fieldClasses);
    }

    /**
     * Get the formfield to use when editing the title inline
     *
     * @param string $column
     * @return FormField
     */
    public function getInlineTitleField($column)
    {
        return TextField::create($column, false)
            ->setAttribute('placeholder', _t(__CLASS__.'.TITLE', 'Title'))
            ->setAttribute('data-placeholder', _t(__CLASS__.'.TITLE', 'Title'));
    }

    /**
     * Get the JS expression for selecting the holder for this field
     *
     * @return string
     */
    public function getSelectorHolder()
    {
        return sprintf('$("%s")', $this->getSelectorOnly());
    }

    /**
     * Returns only the JS identifier of a string, less the $(), which can be inserted elsewhere, for example when you
     * want to perform selections on multiple selectors
     * @return string
     */
    public function getSelectorOnly()
    {
        return "#{$this->Name}";
    }

    /**
     * Gets the JS expression for selecting the value for this field
     *
     * @param EditableCustomRule $rule Custom rule this selector will be used with
     * @param bool $forOnLoad Set to true if this will be invoked on load
     *
     * @return string
     */
    public function getSelectorField(EditableCustomRule $rule, $forOnLoad = false)
    {
        return sprintf("$(%s)", $this->getSelectorFieldOnly());
    }

    /**
     * @return string
     */
    public function getSelectorFieldOnly()
    {
        return "[name='{$this->Name}']";
    }


    /**
     * Get the list of classes that can be selected and used as data-values
     *
     * @param $includeLiterals Set to false to exclude non-data fields
     * @return array
     */
    public function getEditableFieldClasses($includeLiterals = true)
    {
        $classes = ClassInfo::getValidSubClasses(EditableFormField::class);

        // Remove classes we don't want to display in the dropdown.
        $editableFieldClasses = [];
        foreach ($classes as $class) {
            // Skip abstract / hidden classes
            if (Config::inst()->get($class, 'abstract', Config::UNINHERITED) || Config::inst()->get($class, 'hidden')
            ) {
                continue;
            }

            if (!$includeLiterals && Config::inst()->get($class, 'literal')) {
                continue;
            }

            $singleton = singleton($class);
            if (!$singleton->canCreate()) {
                continue;
            }

            $editableFieldClasses[$class] = $singleton->i18n_singular_name();
        }

        asort($editableFieldClasses);
        return $editableFieldClasses;
    }

    /**
     * @return EditableFormFieldValidator
     */
    public function getCMSValidator()
    {
        return EditableFormFieldValidator::create()
            ->setRecord($this);
    }

    /**
     * Determine effective display rules for this field.
     *
     * @return SS_List
     */
    public function EffectiveDisplayRules()
    {
        if ($this->Required) {
            return ArrayList::create();
        }
        return $this->DisplayRules();
    }

    /**
     * Extracts info from DisplayRules into array so UserDefinedForm->buildWatchJS can run through it.
     * @return array|null
     */
    public function formatDisplayRules()
    {
        $holderSelector = $this->getSelectorOnly();
        $result = [
            'targetFieldID' => $holderSelector,
            'conjunction'   => $this->DisplayRulesConjunctionNice(),
            'selectors'     => [],
            'events'        => [],
            'operations'    => [],
            'initialState'  => $this->ShowOnLoadNice(),
            'view'          => [],
            'opposite'      => [],
        ];

        // Check for field dependencies / default
        /** @var EditableCustomRule $rule */
        foreach ($this->EffectiveDisplayRules() as $rule) {
            // Get the field which is effected
            /** @var EditableFormField $formFieldWatch */
            $formFieldWatch = DataObject::get_by_id(EditableFormField::class, $rule->ConditionFieldID);
            // Skip deleted fields
            if (! $formFieldWatch) {
                continue;
            }
            $fieldToWatch = $formFieldWatch->getSelectorFieldOnly();

            $expression = $rule->buildExpression();
            if (! in_array($fieldToWatch, $result['selectors'])) {
                $result['selectors'][] = $fieldToWatch;
            }
            if (! in_array($expression['event'], $result['events'])) {
                $result['events'][] = $expression['event'];
            }
            $result['operations'][] = $expression['operation'];

            // View/Show should read
            $opposite = ($result['initialState'] === 'hide') ? 'show' : 'hide';
            $result['view'] = $rule->toggleDisplayText($result['initialState']);
            $result['opposite'] = $rule->toggleDisplayText($opposite);
        }

        return (count($result['selectors'])) ? $result : null;
    }

    /**
     * Replaces the set DisplayRulesConjunction with their JS logical operators
     * @return string
     */
    public function DisplayRulesConjunctionNice()
    {
        return (strtolower($this->DisplayRulesConjunction) === 'or') ? '||' : '&&';
    }

    /**
     * Replaces boolean ShowOnLoad with its JS string equivalent
     * @return string
     */
    public function ShowOnLoadNice()
    {
        return ($this->ShowOnLoad) ? 'show' : 'hide';
    }

    /**
     * Returns whether this is of type EditableCheckBoxField
     * @return bool
     */
    public function isCheckBoxField()
    {
        return false;
    }

    /**
     * Returns whether this is of type EditableRadioField
     * @return bool
     */
    public function isRadioField()
    {
        return false;
    }

    /**
     * Determined is this is of type EditableCheckboxGroupField
     * @return bool
     */
    public function isCheckBoxGroupField()
    {
        return false;
    }
}
