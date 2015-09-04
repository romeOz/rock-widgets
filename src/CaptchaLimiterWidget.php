<?php

namespace rock\widgets;


use rock\snippets\filters\RateLimiter;

class CaptchaLimiterWidget extends CaptchaWidget
{
    /**
     * @var bool
     */
    public $sendHeaders = false;
    /**
     * Count of iteration.
     * @var int
     */
    public $limit = 8;
    /**
     * Period rate limit (second).
     * @var int
     */
    public $period = 16;
    public $dependency = true;
    /**
     * Hash-key.
     * @var string
     */
    public $name;
    public $invert = true;

    public function behaviors()
    {
        $dependency = $this->dependency;
        $name = get_class($this);
        if ($this->hasModel() && $this->activeField) {
            $dependency = isset($this->activeField->form->submitted) ? $this->activeField->form->submitted : $this->dependency;
            $name = get_class($this->model) . '::' . $this->attribute;
        }

        return [
            'rateLimiter' => [
                'class' => RateLimiter::className(),
                'limit' => $this->limit,
                'period' => $this->period,
                'name' => $name,
                'sendHeaders' => $this->sendHeaders,
                'dependency' => $dependency,
                'invert' => $this->invert
            ],
        ];
    }
} 