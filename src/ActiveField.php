<?php

namespace rock\widgets;

use rock\base\BaseException;
use rock\base\ObjectInterface;
use rock\base\ObjectTrait;
use rock\cache\CacheInterface;
use rock\components\Model;
use rock\helpers\Instance;
use rock\helpers\Json;
use rock\i18n\i18n;
use rock\log\Log;

class ActiveField implements ObjectInterface
{
    use ObjectTrait;

    /**
     * @var ActiveForm the form that this field is associated with.
     */
    public $form;
    /**
     * @var Model the data model that this field is associated with
     */
    public $model;
    /**
     * @var string the model attribute that this field is associated with
     */
    public $attribute;
    /**
     * @var array the HTML attributes (name-value pairs) for the field container tag.
     * The values will be HTML-encoded using {@see \rock\template\Html::encode()}.
     * If a value is null, the corresponding attribute will not be rendered.
     * The following special options are recognized:
     *
     * - tag: the tag name of the container element. Defaults to "div".
     *
     * @see \rock\helpers\Html::renderTagAttributes() for details on how attributes are being rendered.
     */
    public $options = ['class' => 'form-group'];
    public $required = false;
    public $rateLimiter = [];

    /**
     * @var string the template that is used to arrange the label, the input field, the error message and the hint text.
     * The following tokens will be replaced when `render()` is called: `{label}`, `{input}`, `{error}` and `{hint}`.
     */
    public $template = "{label}\n{input}\n{hint}\n{error}";
    /**
     * @var array the default options for the input tags. The parameter passed to individual input methods
     * (e.g. `textInput()`) will be merged with this property when rendering the input tag.
     * @see \rock\helpers\Html::Html::renderTagAttributes()} for details on how attributes are being rendered.
     */
    public $inputOptions = ['class' => 'form-control'];
    /**
     * @var array the default options for the error tags. The parameter passed to `error()` will be
     * merged with this property when rendering the error tag.
     * The following special options are recognized:
     *
     * - tag: the tag name of the container element. Defaults to "div".
     *
     * @see \rock\helpers\Html::renderTagAttributes() for details on how attributes are being rendered.
     */
    public $errorOptions = ['class' => 'form-error'];
    /**
     * @var array the default options for the error tags. The parameter passed to `error()` will be
     * merged with this property when rendering the error tag.
     * The following special options are recognized:
     *
     * - tag: the tag name of the container element. Defaults to "div".
     *
     * @see \rock\helpers\Html::renderTagAttributes() for details on how attributes are being rendered.
     */
    public $ngErrorOptions = ['class' => 'form-error ng-hide'];
    public $ngErrorMessages = [];
    /**
     * @var array the default options for the label tags. The parameter passed to `label()` will be
     * merged with this property when rendering the label tag.
     * @see \rock\helpers\Html::renderTagAttributes() for details on how attributes are being rendered.
     */
    public $labelOptions = ['class' => 'form-label'];
    /**
     * @var array the default options for the hint tags. The parameter
     * passed to @see hint() will be
     * merged with this property when rendering the hint tag.
     * The following special options are recognized:
     *
     * - tag: the tag name of the container element. Defaults to `div`.
     *
     * @see \rock\helpers\Html::renderTagAttributes() for details on how attributes are being rendered.
     */
    public $hintOptions = ['class' => 'form-hint'];
    /**
     * @var boolean whether to enable client-side data validation.
     * If not set, it will take the value of {@see \rock\widgets\ActiveForm::enableClientValidation}.
     */
    public $enableClientValidation = true;
    /**
     * @var boolean whether to perform validation when the input field loses focus and its value is found changed.
     * If not set, it will take the value of {@see \rock\widgets\ActiveForm::validateOnChanged}.
     */
    public $validateOnChanged = false;
    /**
     * @var array different parts of the field (e.g. input, label). This will be used together with
     * {@see \rock\widgets\ActiveField::$template} to generate the final field HTML code. The keys are the
     * token names in {@see \rock\widgets\ActiveField::$template} ,
     * while the values are the corresponding HTML code. Valid tokens include `{input}`, `{label}` and `{error}`.
     * Note that you normally don't need to access this property directly as
     * it is maintained by various methods of this class.
     */
    public $parts = [];
    /**
     * @var string|array|CacheInterface the cache object or the ID of the cache application component
     * that is used for query caching.
     * @see enableCache
     */
    public $cache = 'cache';
    /**
     * @var boolean whether to enable query caching.
     * Note that in order to enable query caching, a valid cache component as specified
     * by `cacheClass` must be enabled and `enableCache` must be set true.
     *
     * Methods @see cache
     * and @see notCache can be used as shortcuts to turn on
     * and off query caching on the fly.
     * @see cacheExpire
     * @see cacheClass
     * @see cacheTags
     * @see cache()
     * @see notCache()
     */
    public $enableCache = false;
    /**
     * @var integer number of seconds that query results can remain valid in cache.
     * Defaults to 0, meaning 0 seconds, or one hour.
     * Use 0 to indicate that the cached data will never expire.
     * @see enableCache
     */
    public $cacheExpire = 0;
    /**
     * @var string[] the dependency that will be used when saving query results into cache.
     * Defaults to null, meaning no dependency.
     * @see enableCache
     */
    public $cacheTags = [];
    /** @var string */
    protected $formName = 'form';

    public function init()
    {
        if ($formName = $this->model->formName()) {
            $this->formName = $formName;
        }

        $this->cache = Instance::ensure($this->cache, '\rock\cache\CacheFile', [], false);
    }

    /**
     * PHP magic method that returns the string representation of this object.
     *
     * @return string the string representation of this object.
     */
    public function __toString()
    {
        // __toString cannot throw exception
        // use trigger_error to bypass this limitation
        try {
            return $this->render();
        } catch (\Exception $e) {
            if (class_exists('\rock\log\Log')) {
                Log::err(BaseException::convertExceptionToString($e));
            }
            return '';
        }
    }

    /**
     * Renders the whole field.
     * This method will generate the label, error tag, input tag and hint tag (if any), and
     * assemble them into HTML according to {@see \rock\widgets\ActiveField::$template} .
     *
     * @param string|callable $content the content within the field container.
     *                                 If null (not set), the default methods will be called to generate the label, error tag and input tag,
     *                                 and use them as the content.
     *                                 If a callable, it will be called to generate the content. The signature of the callable should be:
     *
     * ```php
     * function ($field) {
     *     return $html;
     * }
     * ```
     *
     * @return string the rendering result
     */
    public function render($content = null)
    {
        if ($content === null) {
            $content = $this->_calculateParts();
        } elseif (!is_string($content)) {
            $content = call_user_func($content, $this);
        }

        return $this->begin() . "\n" . $content . "\n" . $this->end();
    }

    public function calculateClientInputOption($options = [])
    {
        $formName = $this->formName;
        if (!isset($options['data']['ng-model'])) {
            $options['data']['ng-model'] = isset($formName)
                ? "{$formName}.values.{$this->attribute}"
                : "form.values.{$this->attribute}";
        }
        if ($this->enableClientValidation && !isset($options['data']['ng-class'])) {
            $options['data']['ng-class'] = isset($formName)
                ? 'showHighlightError("' . $formName . '[' . $this->attribute . ']")'
                : 'showHighlightError("' . $this->attribute . '")';
        }
        if ($this->enableClientValidation && $this->validateOnChanged) {
            $options['data']['rock-form-focus'] = '';
        }
        if (isset($options['value']) && empty($options['value']) && !isset($options['data']['rock-reset-field'])) {
            $options['data']['rock-reset-field'] = '';
        }
        return $this->calculateValidateOptions($options);
    }

    protected function renderErrors()
    {
        $result = '';
        if ($this->enableClientValidation) {
            $tag = isset($this->ngErrorOptions['tag']) ? $this->ngErrorOptions['tag'] : 'div';
            unset($this->ngErrorOptions['tag']);
            $formName = isset($this->formName) ? $this->formName . '[' . $this->attribute . ']' : $this->attribute;

            if ($this->ngErrorMessages) {
                if (is_array($this->ngErrorMessages)) {
                    $this->ngErrorMessages = Json::encode($this->ngErrorMessages);
                }

                $this->ngErrorOptions['data']['ng-repeat'] = "(rule, errorMsg) in {$this->ngErrorMessages}";
                $this->ngErrorOptions['data']['ng-show'] = 'showError("' . $formName . '", rule)';
                $this->ngErrorOptions['data']['ng-bind'] = "errorMsg";
                $result .= ActiveHtml::tag($tag, '', $this->ngErrorOptions) . "\n";
            }
            $result .= ActiveHtml::tag($tag, '', ['class' => $this->ngErrorOptions['class'], 'data' => ['ng-show' => "!!bindError(\"{$this->attribute}\")", 'ng-bind' => "bindError(\"{$this->attribute}\")"]]) . "\n";
            $this->errorOptions['data']['ng-hide'] = 'true';
        }
        $result .= ActiveHtml::error($this->model, $this->attribute, $this->errorOptions);

        return $result;
    }

    /**
     * Renders the opening tag of the field container.
     *
     * @return string the rendering result.
     */
    public function begin()
    {
        $inputID = ActiveHtml::getInputId($this->model, $this->attribute);
        $attribute = ActiveHtml::getAttributeName($this->attribute);
        $options = $this->options;
        $class = isset($options['class']) ? [$options['class']] : [];
        $class[] = "field-$inputID";
        if (isset($this->form) && $this->model->isAttributeRequired($attribute)) {
            $class[] = $this->form->requiredCssClass;
        }
        if ($this->model->hasErrors($attribute) && isset($this->form)) {
            $class[] = $this->form->errorCssClass;
        }
        $options['class'] = implode(' ', $class);
        $tag = ActiveHtml::remove($options, 'tag', 'div');

        return ActiveHtml::beginTag($tag, $options);
    }

    /**
     * Renders the closing tag of the field container.
     *
     * @return string the rendering result.
     */
    public function end()
    {
        return ActiveHtml::endTag(isset($this->options['tag']) ? $this->options['tag'] : 'div');
    }

    /**
     * Generates a label tag for {@see \rock\widgets\ActiveField::$attribute}.
     *
     * @param string|boolean $label the label to use. If null, the label will be generated
     *                                via {@see \rock\components\Model::getAttributeLabel()}.
     *                                If false, the generated field will not contain the label part. Note that this will NOT be {@see \rock\template\Html::encode()}.
     * @param array $options the tag options in terms of name-value pairs. It will be merged
     *                                with {@see \rock\widgets\ActiveField::$labelOptions}.
     *                                The options will be rendered as the attributes of the resulting tag. The values will be HTML-encoded
     *                                using {@see \rock\template\Html::encode()}. If a value is null, the corresponding attribute will not be rendered.
     * @return static the field object itself
     */
    public function label($label = null, $options = [])
    {
        if ($label === false) {
            $this->parts['{label}'] = '';

            return $this;
        }
        $options = array_merge($this->labelOptions, $options);
        if ($label !== null) {
            $options['label'] = $label;
        }
        $this->parts['{label}'] = ActiveHtml::activeLabel($this->model, $this->attribute, $options);

        return $this;
    }

    /**
     * Generates a tag that contains the first validation error of {@see \rock\widgets\ActiveField::$attribute}.
     *
     * Note that even if there is no validation error, this method will still return an empty error tag.
     *
     * @param array|boolean $options the tag options in terms of name-value pairs. It will be merged
     *                               with @see errorOptions .
     *                               The options will be rendered as the attributes of the resulting tag. The values will be HTML-encoded
     *                               using {@see \rock\template\Html::encode()} . If a value is null, the corresponding attribute will not be rendered.
     *
     * The following options are specially handled:
     *
     * - tag: this specifies the tag name. If not set, `div` will be used.
     *
     * If this parameter is false, no error tag will be rendered.
     *
     * @return static the field object itself
     */
    public function error($options = [])
    {
        if ($options === false) {
            $this->parts['{error}'] = '';

            return $this;
        }
        $options = array_merge($this->errorOptions, $options);
        $this->parts['{error}'] = ActiveHtml::error($this->model, $this->attribute, $options);

        return $this;
    }

    /**
     * Renders the hint tag.
     *
     * @param string $content the hint content. It will NOT be HTML-encoded.
     * @param array $options the tag options in terms of name-value pairs. These will be rendered as
     *                        the attributes of the hint tag. The values will be HTML-encoded using {@see \rock\template\Html::encode()}.
     *
     * The following options are specially handled:
     *
     * - tag: this specifies the tag name. If not set, `div` will be used.
     *
     * @return static the field object itself
     */
    public function hint($content, $options = [])
    {
        $options = array_merge($this->hintOptions, $options);
        $tag = ActiveHtml::remove($options, 'tag', 'div');
        $this->parts['{hint}'] = ActiveHtml::tag($tag, $content, $options);

        return $this;
    }

    /**
     * Renders an input tag.
     *
     * @param string $type the input type (e.g. 'text', 'password')
     * @param array $options the tag options in terms of name-value pairs. These will be rendered as
     *                        the attributes of the resulting tag. The values will be HTML-encoded using {@see \rock\template\Html::encode()}.
     * @return static the field object itself
     */
    public function input($type, $options = [])
    {
        $options = array_merge($this->inputOptions, $options);
        $options = $this->calculateClientInputOption($options);
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activeInput($type, $this->model, $this->attribute, $options);

        return $this;
    }

    /**
     * Adjusts the "for" attribute for the label based on the input options.
     *
     * @param array $options the input options
     */
    protected function adjustLabelFor($options)
    {
        if (isset($options['id']) && !isset($this->labelOptions['for'])) {
            $this->labelOptions['for'] = $options['id'];
        }
    }

    /**
     * Renders a text input.
     *
     * This method will generate the "name" and "value" tag attributes automatically for the model attribute
     * unless they are explicitly specified in `$options`.
     *
     * @param array $options the tag options in terms of name-value pairs. These will be rendered as
     *                       the attributes of the resulting tag. The values will be HTML-encoded using {@see \rock\template\Html::encode()}.
     * @return static the field object itself
     */
    public function textInput($options = [])
    {
        $options = array_merge($this->inputOptions, $options);
        $options = $this->calculateClientInputOption($options);
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activeTextInput($this->model, $this->attribute, $options);

        return $this;
    }

    /**
     * Renders a hidden input.
     *
     * Note that this method is provided for completeness. In most cases because you do not need
     * to validate a hidden input, you should not need to use this method. Instead, you should
     * use @see ActiveHtml::activeHiddenInput() .
     *
     * This method will generate the "name" and "value" tag attributes automatically for the model attribute
     * unless they are explicitly specified in `$options`.
     *
     * @param array $options the tag options in terms of name-value pairs. These will be rendered as
     *                       the attributes of the resulting tag. The values will be HTML-encoded using {@see \rock\template\Html::encode()} .
     * @return static the field object itself
     */
    public function hiddenInput($options = [])
    {
        $options = array_merge($this->inputOptions, $options);
        $options = $this->calculateClientInputOption($options);
        if (!isset($options['data']['ng-init']) && isset($options['value']) && trim($options['value']) !== '') {
            if (!isset($options['data']['ng-init'])) {
                $options['data']['ng-init'] = [];
            }
            $key = isset($this->formName) ? "{$this->formName}.values.{$this->attribute}" : "form.values.{$this->attribute}";

            if (!isset($options['data']['ng-init'][$key])) {
                $options['data']['ng-init'][$key] = $options['value'];
            }
        }
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activeHiddenInput($this->model, $this->attribute, $options);

        return $this;
    }

    /**
     * Renders a password input.
     *
     * This method will generate the "name" and "value" tag attributes automatically for the model attribute
     * unless they are explicitly specified in `$options`.
     *
     * @param array $options the tag options in terms of name-value pairs. These will be rendered as
     *                       the attributes of the resulting tag. The values will be HTML-encoded using {@see \rock\template\Html::encode()} .
     * @return static the field object itself
     */
    public function passwordInput($options = [])
    {
        $options = array_merge($this->inputOptions, $options);
        $options = $this->calculateClientInputOption($options);
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activePasswordInput($this->model, $this->attribute, $options);

        return $this;
    }

    /**
     * Renders a file input.
     *
     * This method will generate the "name" and "value" tag attributes automatically for the model attribute
     * unless they are explicitly specified in `$options`.
     *
     * @param array $options the tag options in terms of name-value pairs. These will be rendered as
     *                       the attributes of the resulting tag. The values will be HTML-encoded using {@see \rock\template\Html::encode()} .
     * @return static the field object itself
     */
    public function fileInput($options = [])
    {
        if ($this->inputOptions !== ['class' => 'form-control']) {
            $options = array_merge($this->inputOptions, $options);
        }
        $options = $this->calculateClientInputOption($options);
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activeFileInput($this->model, $this->attribute, $options);

        return $this;
    }

    /**
     * Renders a text area.
     *
     * The model attribute value will be used as the content in the textarea.
     *
     * @param array $options the tag options in terms of name-value pairs. These will be rendered as
     *                       the attributes of the resulting tag. The values will be HTML-encoded using {@see \rock\template\Html::encode()} .
     * @return static the field object itself
     */
    public function textarea($options = [])
    {
        $options = array_merge($this->inputOptions, $options);
        $options = $this->calculateClientInputOption($options);
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activeTextarea($this->model, $this->attribute, $options);

        return $this;
    }

    /**
     * Renders a radio button.
     *
     * This method will generate the "checked" tag attribute according to the model attribute value.
     *
     * @param array $options the tag options in terms of name-value pairs. The following options are specially handled:
     *
     * - uncheck: string, the value associated with the uncheck state of the radio button. If not set,
     *   it will take the default value '0'. This method will render a hidden input so that if the radio button
     *   is not checked and is submitted, the value of this attribute will still be submitted to the server
     *   via the hidden input.
     * - label: string, a label displayed next to the radio button.  It will NOT be HTML-encoded. Therefore you can pass
     *   in HTML code such as an image tag. If this is is coming from end users, you should {@see \rock\template\Html::encode()} it to prevent XSS attacks.
     *   When this option is specified, the radio button will be enclosed by a label tag.
     * - labelOptions: array, the HTML attributes for the label tag. This is only used when the "label" option is specified.
     *
     * The rest of the options will be rendered as the attributes of the resulting tag. The values will
     * be HTML-encoded using {@see \rock\template\Html::encode()} . If a value is null, the corresponding attribute will not be rendered.
     * @param boolean $enclosedByLabel whether to enclose the radio within the label.
     *                                 If true, the method will still use `template` to layout the checkbox and the error message
     *                                 except that the radio is enclosed by the label tag.
     * @return static the field object itself
     */
    public function radio($options = [], $enclosedByLabel = true)
    {
        $options = $this->calculateClientInputOption($options);
        if ($enclosedByLabel) {
            $this->parts['{input}'] = ActiveHtml::activeRadio($this->model, $this->attribute, $options);
            $this->parts['{label}'] = '';
        } else {
            if (isset($options['label']) && !isset($this->parts['{label}'])) {
                $this->parts['{label}'] = $options['label'];
                if (!empty($options['labelOptions'])) {
                    $this->labelOptions = $options['labelOptions'];
                }
            }
            unset($options['label'], $options['labelOptions']);
            $this->parts['{input}'] = ActiveHtml::activeRadio($this->model, $this->attribute, $options);
        }
        $this->adjustLabelFor($options);

        return $this;
    }

    /**
     * Renders a checkbox.
     *
     * This method will generate the "checked" tag attribute according to the model attribute value.
     *
     * @param array $options the tag options in terms of name-value pairs. The following options are specially handled:
     *
     * - uncheck: string, the value associated with the uncheck state of the radio button. If not set,
     *   it will take the default value '0'. This method will render a hidden input so that if the radio button
     *   is not checked and is submitted, the value of this attribute will still be submitted to the server
     *   via the hidden input.
     * - label: string, a label displayed next to the checkbox.  It will NOT be HTML-encoded. Therefore you can pass
     *   in HTML code such as an image tag. If this is is coming from end users, you should {@see \rock\template\Html::encode()} it to prevent XSS attacks.
     *   When this option is specified, the checkbox will be enclosed by a label tag.
     * - labelOptions: array, the HTML attributes for the label tag. This is only used when the "label" option is specified.
     *
     * The rest of the options will be rendered as the attributes of the resulting tag. The values will
     * be HTML-encoded using {@see \rock\template\Html::encode()} . If a value is null, the corresponding attribute will not be rendered.
     * @param boolean $enclosedByLabel whether to enclose the checkbox within the label.
     *                                 If true, the method will still use {@see \rock\widgets\ActiveField::$template} to layout the checkbox and the error message
     *                                 except that the checkbox is enclosed by the label tag.
     * @return static the field object itself
     */
    public function checkbox($options = [], $enclosedByLabel = true)
    {
        $options = $this->calculateClientInputOption($options);
        if ($enclosedByLabel) {
            $this->parts['{input}'] = ActiveHtml::activeCheckbox($this->model, $this->attribute, $options);
            $this->parts['{label}'] = '';
        } else {
            if (isset($options['label']) && !isset($this->parts['{label}'])) {
                $this->parts['{label}'] = $options['label'];
                if (!empty($options['labelOptions'])) {
                    $this->labelOptions = $options['labelOptions'];
                }
            }
            unset($options['labelOptions']);
            $options['label'] = null;
            $this->parts['{input}'] = ActiveHtml::activeCheckbox($this->model, $this->attribute, $options);
        }
        $this->adjustLabelFor($options);

        return $this;
    }

    /**
     * Renders a drop-down list.
     * The selection of the drop-down list is taken from the value of the model attribute.
     *
     * @param array|callable $items the option data items. The array keys are option values, and the array values
     *                                are the corresponding option labels. The array can also be nested (i.e. some array values are arrays too).
     *                                For each sub-array, an option group will be generated whose label is the key associated with the sub-array.
     *                                If you have a list of data models, you may convert them into the format described above using {@see \rock\helpers\ArrayHelper::map()}.
     *
     * Note, the values and labels will be automatically HTML-encoded by this method, and the blank spaces in
     * the labels will also be HTML-encoded.
     * @param array $options the tag options in terms of name-value pairs. The following options are specially handled:
     *
     * - prompt: string, a prompt text to be displayed as the first option;
     * - options: array, the attributes for the select option tags. The array keys must be valid option values,
     *   and the array values are the extra attributes for the corresponding option tags. For example,
     *
     * ```php
     * [
     *     'value1' => ['disabled' => true],
     *     'value2' => ['label' => 'value 2'],
     * ];
     * ```
     *
     * - groups: array, the attributes for the optgroup tags. The structure of this is similar to that of 'options',
     *   except that the array keys represent the optgroup labels specified in `$items`.
     *
     * The rest of the options will be rendered as the attributes of the resulting tag. The values will
     * be HTML-encoded using {@see \rock\template\Html::encode()} . If a value is null, the corresponding attribute will not be rendered.
     *
     * @return static the field object itself
     */
    public function dropDownList($items, $options = [])
    {
        if (($result = $this->getCache(__METHOD__)) !== false) {
            $this->parts['{input}'] = $result;
            return $this;
        }

        if ($items instanceof \Closure) {
            $items = call_user_func($items, $this);
        }
        $options = array_merge($this->inputOptions, $options);
        $options = $this->calculateClientInputOption($options);
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activeDropDownList($this->model, $this->attribute, $items, $options);

        $this->setCache(__METHOD__, $this->parts['{input}']);

        return $this;
    }

    /**
     * Renders a list box.
     *
     * The selection of the list box is taken from the value of the model attribute.
     *
     * @param array $items the option data items. The array keys are option values, and the array values
     *                       are the corresponding option labels. The array can also be nested (i.e. some array values are arrays too).
     *                       For each sub-array, an option group will be generated whose label is the key associated with the sub-array.
     *                       If you have a list of data models, you may convert them into the format described above using {@see \rock\helpers\ArrayHelper::map()} .
     *
     * Note, the values and labels will be automatically HTML-encoded by this method, and the blank spaces in
     * the labels will also be HTML-encoded.
     * @param array $options the tag options in terms of name-value pairs. The following options are specially handled:
     *
     * - prompt: string, a prompt text to be displayed as the first option;
     * - options: array, the attributes for the select option tags. The array keys must be valid option values,
     *   and the array values are the extra attributes for the corresponding option tags. For example,
     *
     * ```php
     * [
     *     'value1' => ['disabled' => true],
     *     'value2' => ['label' => 'value 2'],
     * ];
     * ```
     *
     * - groups: array, the attributes for the optgroup tags. The structure of this is similar to that of 'options',
     *   except that the array keys represent the optgroup labels specified in `$items`.
     * - unselect: string, the value that will be submitted when no option is selected.
     *   When this attribute is set, a hidden field will be generated so that if no option is selected in multiple
     *   mode, we can still obtain the posted unselect value.
     *
     * The rest of the options will be rendered as the attributes of the resulting tag. The values will
     * be HTML-encoded using {@see \rock\template\Html::encode()} . If a value is null, the corresponding attribute will not be rendered.
     *
     * @return static the field object itself
     */
    public function listBox($items, $options = [])
    {
        if (($result = $this->getCache(__METHOD__)) !== false) {
            $this->parts['{input}'] = $result;
            return $this;
        }
        if ($items instanceof \Closure) {
            $items = call_user_func($items, $this);
        }
        $options = array_merge($this->inputOptions, $options);
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activeListBox($this->model, $this->attribute, $items, $options);
        $this->setCache(__METHOD__, $this->parts['{input}']);

        return $this;
    }

    /**
     * Renders a list of checkboxes.
     *
     * A checkbox list allows multiple selection, like {@see \rock\widgets\ActiveField::listBox()} .
     * As a result, the corresponding submitted value is an array.
     * The selection of the checkbox list is taken from the value of the model attribute.
     *
     * @param array|callable $items the data item used to generate the checkboxes.
     *                                The array values are the labels, while the array keys are the corresponding checkbox values.
     *                                Note that the labels will NOT be HTML-encoded, while the values will.
     * @param array $options options (name => config) for the checkbox list. The following options are specially handled:
     *
     * - unselect: string, the value that should be submitted when none of the checkboxes is selected.
     *   By setting this option, a hidden input will be generated.
     * - separator: string, the HTML code that separates items.
     * - item: callable, a callback that can be used to customize the generation of the HTML code
     *   corresponding to a single item in $items. The signature of this callback must be:
     *
     * ```php
     * function ($index, $label, $name, $checked, $value)
     * ```
     *
     * where $index is the zero-based index of the checkbox in the whole list; $label
     * is the label for the checkbox; and $name, $value and $checked represent the name,
     * value and the checked status of the checkbox input.
     * @return static the field object itself
     */
    public function checkboxList($items, $options = [])
    {
        if (($result = $this->getCache(__METHOD__)) !== false) {
            $this->parts['{input}'] = $result;
            return $this;
        }
        if ($items instanceof \Closure) {
            $items = call_user_func($items, $this);
        }
        $this->adjustLabelFor($options);
        $this->parts['{input}'] = ActiveHtml::activeCheckboxList($this->model, $this->attribute, $items, $options);
        $this->setCache(__METHOD__, $this->parts['{input}']);

        return $this;
    }

    /**
     * Renders a list of radio buttons.
     * A radio button list is like a checkbox list, except that it only allows single selection.
     * The selection of the radio buttons is taken from the value of the model attribute.
     *
     * @param array|callable $items the data item used to generate the radio buttons.
     *                                The array keys are the labels, while the array values are the corresponding radio button values.
     *                                Note that the labels will NOT be HTML-encoded, while the values will.
     * @param array $options options (name => config) for the radio button list. The following options are specially handled:
     *
     * - unselect: string, the value that should be submitted when none of the radio buttons is selected.
     *   By setting this option, a hidden input will be generated.
     * - separator: string, the HTML code that separates items.
     * - item: callable, a callback that can be used to customize the generation of the HTML code
     *   corresponding to a single item in $items. The signature of this callback must be:
     *
     * ```php
     * function ($index, $label, $name, $checked, $value)
     * ```
     *
     * where $index is the zero-based index of the radio button in the whole list; $label
     * is the label for the radio button; and $name, $value and $checked represent the name,
     * value and the checked status of the radio button input.
     * @return static the field object itself
     */
    public function radioList($items, $options = [])
    {
        if (($result = $this->getCache(__METHOD__)) !== false) {
            $this->parts['{input}'] = $result;
            return $this;
        }
        if ($items instanceof \Closure) {
            $items = call_user_func($items, $this);
        }
        $this->adjustLabelFor($options);
        if (!isset($options['itemOptions']['data']['ng-model'])) {
            $options['itemOptions']['data']['ng-model'] = "{$this->formName}.values.{$this->attribute}";
        }
        $this->parts['{input}'] = ActiveHtml::activeRadioList($this->model, $this->attribute, $items, $options);
        $this->setCache(__METHOD__, $this->parts['{input}']);

        return $this;
    }

    /**
     * Renders a widget as the input of the field.
     *
     * Note that the widget must have both `model` and `attribute` properties. They will
     * be initialized with `model` and `attribute` of this field, respectively.
     *
     * If you want to use a widget that does not have `model` and `attribute` properties,
     * please use {@see \rock\widgets\ActiveField::render()} instead.
     *
     * For example to use the {@see \rock\widgets\Captcha} widget to get some date input, you can use
     * the following code, assuming that `$form` is your {@see \rock\widgets\ActiveForm} instance:
     *
     * ```php
     * $form->field($model, 'captcha')->widget(\rock\widgets\Captcha::className(), [
     *     'output'=> \rock\widgets\Captcha:BASE64,
     * ]);
     * ```
     *
     * @param string $class the widget class name
     * @param array $config name-value pairs that will be used to initialize the widget
     * @return static the field object itself
     */
    public function widget($class, $config = [])
    {
        /** @var Widget $class */
        $config['model'] = $this->model;
        $config['attribute'] = $this->attribute;
        $config['activeField'] = $this;
        //$config['view'] = $this->form->getView();
        $this->parts['{input}'] = $class::widget($config);

        return $this;
    }

    /**
     * Turns on query caching.
     * This method is provided as a shortcut to setting two properties that are related
     * with query caching: `cacheExpire` and `cacheTags`.
     *
     * @param int $expire
     * @param string[] $tags the tags for the cached query result.
     *                       See `cacheTags` for more details.
     *                       If not set, it will use the value of `cacheExpire`. See `cacheExpire` for more details.
     * @return $this
     */
    public function cache($expire = 0, array $tags = [])
    {
        $this->enableCache = true;
        if ($expire !== null) {
            $this->cacheExpire = $expire;
        }
        $this->cacheTags = $tags;

        return $this;
    }

    /**
     * Turns off query caching.
     */
    public function notCache()
    {
        $this->enableCache = false;

        return $this;
    }

    /**
     * Returns widget.
     *
     * @param string|null $key
     * @return bool
     */
    protected function getCache($key = null)
    {
        if (!$this->enableCache || !$this->cache instanceof \rock\cache\CacheInterface || !isset($key)) {
            return false;
        }
        $key = $this->getCacheKey($key);

        if (($returnCache = $this->cache->get($key)) !== false) {
            return $returnCache;
        }

        return false;
    }

    /**
     * Caching widget.
     *
     * @param string $key
     * @param mixed $value
     */
    protected function setCache($key = null, $value = null)
    {
        if (!$this->enableCache || !$this->cache instanceof \rock\cache\CacheInterface || !isset($key)) {
            return;
        }
        $key = $this->getCacheKey($key);
        $this->cache->set($key, $value, $this->cacheExpire, $this->cacheTags);
    }

    protected function getCacheKey($method)
    {
        $model = $this->model;

        return $model::className() . $this->attribute . $method;
    }

    protected function calculateValidateOptions(array $options)
    {
        if (!$this->enableClientValidation) {
            return $options;
        }
        foreach ($this->model->rules() as $rule) {
            list($attributes) = $rule;
            if (in_array($this->attribute, (array)$attributes, true)) {
                $rule = array_slice($rule, 1);

                foreach ($rule as $ruleName => $params) {
                    if (is_int($ruleName)) {
                        $ruleName = $params;
                        $params = [];
                    }
                    if ($ruleName === 'length') {
                        $options = $this->calculateLength($options, $params[0], $params[1]);
                        continue;
                    }
                    if ($ruleName === 'max') {
                        $options = $this->calculateMax($options, $params[0]);
                        continue;
                    }
                    if ($ruleName === 'min') {
                        $options = $this->calculateMin($options, $params[0]);
                        continue;
                    }
                    if ($ruleName === 'email') {
                        $options = $this->calculateEmail($options);
                        continue;
                    }
                    if ($ruleName === 'regex') {
                        $options = $this->calculateRegex($options, $params[0]);
                        continue;
                    }
                    if ($ruleName === 'required') {
                        $options = $this->calculateRequired($options);
                        continue;
                    }

                    if ($ruleName === 'confirm' && isset($options['data']['rock-match'])) {
                        $options = $this->calculateConfirm($options);
                    }
                }
            }
        }
        return $options;
    }

    protected function calculateLength(array $options, $min, $max)
    {
        $options = $this->calculateMin($options, $min);
        $options = $this->calculateMax($options, $max);
        return $options;
    }

    protected function calculateMin(array $options, $min)
    {
        if (!isset($options['data']['ng-minlength'])) {
            $options['data']['ng-minlength'] = $min;
        }
        if (!isset($this->ngErrorMessages['minlength']) && class_exists('\rock\i18n\i18n')) {
            $placeholders = [
                'name' => $this->model->getAttributeLabel($this->attribute) ?: i18n::t('value'),
                'minValue' => $options['data']['ng-minlength']
            ];
            $this->ngErrorMessages['minlength'] = i18n::t('min', $placeholders, 'validate') . ' ' . i18n::t('characters');
        }
        return $options;
    }

    protected function calculateMax(array $options, $max)
    {
        if (!isset($options['data']['ng-maxlength'])) {
            $options['data']['ng-maxlength'] = $max;
        }
        if (!isset($this->ngErrorMessages['maxlength']) && class_exists('\rock\i18n\i18n')) {
            $placeholders = [
                'name' => $this->model->getAttributeLabel($this->attribute) ?: i18n::t('value'),
                'maxValue' => $options['data']['ng-maxlength']
            ];
            $this->ngErrorMessages['maxlength'] = i18n::t('max', $placeholders, 'validate') . ' ' . i18n::t('characters');
        }
        return $options;
    }

    protected function calculateEmail(array $options)
    {
        if (!isset($options['data']['ng-pattern'])) {
            $options['data']['ng-pattern'] = '/^([\\wА-яё]+[\\wА-яё\\.\\+\\-]+)?[\\wА-яё]+@([\\wА-яё]+\\.)+[\\wА-яё]+$/i';
        }
        if (!isset($this->ngErrorMessages['pattern']) && class_exists('\rock\i18n\i18n')) {
            $this->ngErrorMessages['pattern'] = i18n::t('email', ['name' => 'email'], 'validate');
        }
        return $options;
    }

    protected function calculateRegex(array $options, $pattern)
    {
        if (!isset($options['data']['ng-pattern'])) {
            $options['data']['ng-pattern'] = $pattern;
        }
        if (!isset($this->ngErrorMessages['pattern']) && class_exists('\rock\i18n\i18n')) {
            $placeholders = ['name' => $this->model->getAttributeLabel($this->attribute) ?: i18n::t('value')];
            $this->ngErrorMessages['pattern'] = i18n::t('regex', $placeholders, 'validate');
        }
        return $options;
    }

    protected function calculateRequired(array $options)
    {
        if (!isset($options['data']['ng-required'])) {
            $options['data']['ng-required'] = 'true';
        }
        if (!isset($this->ngErrorMessages['required']) && class_exists('\rock\i18n\i18n')) {
            $this->ngErrorMessages['required'] = i18n::t('required', ['name' => i18n::t('value')], 'validate');
        }
        return $options;
    }

    /**
     * @param array $options
     * @return array
     */
    protected function calculateConfirm(array $options)
    {
        $options['data']['rock-match'] = isset($this->formName)
            ? "{$this->formName}.values.{$options['data']['rock-match']}"
            : "form.values.{$options['data']['rock-match']}";
        if (!isset($this->ngErrorMessages['match']) && class_exists('\rock\i18n\i18n')) {
            $this->ngErrorMessages['match'] = i18n::t('confirm', [], 'validate');
        }
        return $options;
    }

    private function _calculateParts()
    {
        if (isset($this->parts['{input}']) && $this->parts['{input}'] === '') {
            return '';
        }
        if (!isset($this->parts['{input}'])) {
            $this->inputOptions = $this->calculateClientInputOption($this->inputOptions);
            $this->parts['{input}'] =
                ActiveHtml::activeTextInput($this->model, $this->attribute, $this->inputOptions);
        }
        if (!isset($this->parts['{label}'])) {
            $this->parts['{label}'] = ActiveHtml::activeLabel($this->model, $this->attribute, $this->labelOptions);
        }
        if (!isset($this->parts['{error}'])) {
            $this->parts['{error}'] = $this->renderErrors();
        }
        if (!isset($this->parts['{hint}'])) {
            $this->parts['{hint}'] = ActiveHtml::activeHint($this->model, $this->attribute, $this->hintOptions);
        }
        return strtr($this->template, $this->parts);
    }
}