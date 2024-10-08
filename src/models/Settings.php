<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * A plugin to connect to Elasticsearch and persist elements via hooks
 *
 * @link      http://fork.de
 * @copyright Copyright (c) 2021 Fork Unstable Media GmbH
 */

namespace fork\elastica\models;

use craft\helpers\Json;
use fork\elastica\Elastica;
use Craft;
use craft\base\Model;

/**
 * Elasticsearch Settings Model
 *
 * This is a model used to define the plugin's settings.
 *
 * Models are containers for data. Just about every time information is passed
 * between services, controllers, and templates in Craft, it’s passed via a model.
 *
 * https://craftcms.com/docs/plugins/models
 *
 * @author    Fork Unstable Media GmbH
 * @package   Elastica
 * @since     1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * The host domains for the elasticsearch instance
     *
     * @var mixed
     */
    public $hosts;

    /**
     * Name for the index template for elasticsearch
     *
     * @var string
     */
    public $indexTemplateName;

    /**
     * An index template for elasticsearch
     *
     * @var string
     */
    public $indexTemplate;

    /**
     * Search templates for elasticsearch
     *
     * @var array
     */
    public $searchTemplates;

    // Public Methods
    // =========================================================================

    /**
     * Returns the validation rules for attributes.
     *
     * Validation rules are used by [[validate()]] to check if attribute values are valid.
     * Child classes may override this method to declare different validation rules.
     *
     * More info: http://www.yiiframework.com/doc-2.0/guide-input-validation.html
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            ['hosts', 'required'],
            ['indexTemplate', function ($attribute, $params, $validator) {
                try {
                    if ($this->$attribute) {
                        Json::decode($this->$attribute);
                    }
                } catch (\Exception $exception) {
                    $this->addError($attribute, $exception->getMessage());
                }
            }],
            ['searchTemplates', function ($attribute, $params, $validator) {
                try {
                    if ($this->$attribute) {
                        foreach ($this->$attribute as $row) {
                            if (empty($row[0])) {
                                throw new \Exception("Handle must not be empty");
                            }
                            Json::decode($row[1]);
                            if (! empty($row[2])) {
                                Json::decode($row[2]);
                            }
                        }
                    }
                } catch (\Exception $exception) {
                    $this->addError($attribute, $exception->getMessage());
                }
            }],
        ];
    }
}
