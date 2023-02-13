<?php

namespace fork\elastica\queue;

use Closure;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;
use fork\elastica\Elastica;

/**
 * The ReindexJob class represents re-index processes being added to Craft's job queue.
 *
 * @package fork\elastica\queue
 */
class ReindexJob extends BaseJob
{
    public $deleteAll = false;

    /**
     * Returns a default description for [[getDescription()]].
     *
     * @return string|null
     */
    protected function defaultDescription(): ?string
    {
        return 'Elastica: Re-index Elasticsearch';
    }

    /**
     * @param \yii\queue\Queue|QueueInterface $queue The queue the job belongs to
     */
    public function execute($queue): void
    {
        $this->setProgress($queue, 0);
        Elastica::$plugin->indexer->reIndex($this, $queue, $this->deleteAll);
        $this->setProgress($queue, 1);
    }

    /**
     * Executes a single step of the current job and sets the step's progress and label.
     *
     * @param \craft\queue\QueueInterface $queue the executed queue
     * @param \Closure $closure the background job's step implementation
     * @param float $progress the progress this step corresponds to, must be a value between 0 and 1
     * @param string|null $label label to be printed next to progress as additional information in the Queue Manager
     */
    public function step(QueueInterface $queue, Closure $closure, float $progress = 0.0, string $label = null)
    {
        // ensure progress is a float between 0 and 1
        $progress = !is_numeric($progress) || $progress < 0 ? 0.0 : ($progress > 1 ? 1.0 : floatval($progress));
        // execute the actual step
        $closure();
        // set progress and label
        $this->setProgress($queue, $progress, $label);
    }
}
