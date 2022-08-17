<?php
/**
 * @link      http://fork.de
 * @copyright Copyright (c) 2021 Fork Unstable Media GmbH
 */

namespace fork\elastica\events;

use yii\base\Event;

/**
 * Index event class.
 *
 * @author Fork Unstable Media GmbH
 * @since     1.0.1
 */
class IndexEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var \craft\elements\Entry
     * @deprecated use $sender instead
     */
    public $entry;

    /**
     * @var array
     */
    public $indexData;

}
